<?php

namespace App\Services\Campaigns;

use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignSuppression;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class CampaignManager
{
    /**
     * @param  array{name:string,subject:string,body:string}  $fields
     * @param  list<string>  $keys
     */
    public function saveDraft(User $user, array $fields, array $keys, ?EmailCampaign $existing = null): EmailCampaign
    {
        abort_unless($user->isCommercialManager(), 403);
        if ($existing !== null && $existing->status !== 'draft') {
            throw ValidationException::withMessages(['campaign' => 'Somente rascunhos podem ser editados.']);
        }

        $candidates = app(CampaignAudience::class)->resolve($keys);
        if ($candidates === []) {
            throw ValidationException::withMessages(['recipients' => 'Selecione ao menos um contato com e-mail válido.']);
        }
        if (count(array_filter($candidates, static fn (array $r): bool => ! $r['suppressed'])) === 0) {
            throw ValidationException::withMessages(['recipients' => 'Todos os endereços selecionados estão bloqueados para campanhas.']);
        }

        return DB::transaction(static function () use ($user, $fields, $candidates, $existing): EmailCampaign {
            $campaign = $existing ?? new EmailCampaign;
            $campaign->fill([
                'name' => trim($fields['name']),
                'subject' => trim($fields['subject']),
                'body' => trim($fields['body']),
                'status' => 'draft',
                'from_address' => (string) config('campaign_mail.from_address'),
                'from_name' => (string) config('campaign_mail.from_name'),
                'reply_to' => (string) config('campaign_mail.reply_to'),
                'created_by' => $existing->created_by ?? $user->id,
            ]);
            $campaign->save();
            if ($existing !== null) {
                $campaign->recipients()->delete();
            }
            foreach ($candidates as $candidate) {
                EmailCampaignRecipient::query()->create([
                    'email_campaign_id' => $campaign->id,
                    'source_key' => $candidate['source_key'],
                    'email' => $candidate['email'],
                    'normalized_email' => $candidate['normalized_email'],
                    'contact_name' => $candidate['contact_name'],
                    'company_name' => $candidate['company_name'],
                    'job_title' => $candidate['job_title'],
                    'status' => $candidate['suppressed'] ? 'suppressed' : 'pending',
                ]);
            }

            return $campaign;
        });
    }

    public function start(User $user, EmailCampaign $campaign, bool $lawfulBasisConfirmed): void
    {
        abort_unless($user->isCommercialManager(), 403);
        if (! $lawfulBasisConfirmed) {
            throw ValidationException::withMessages(['lawfulBasisConfirmed' => 'Confirme a base legal e a política de descadastro antes de iniciar.']);
        }
        if (! config('campaign_mail.enabled') || ! config('campaign_mail.live_enabled')) {
            throw ValidationException::withMessages(['campaign' => 'Envios reais estão desabilitados. Configure CAMPAIGN_MAIL_ENABLED=true e CAMPAIGN_MAIL_LIVE_ENABLED=true após os testes.']);
        }
        // EC_CAMPAIGNS_V7_PUBLIC_URL: mantém todas as checagens HTTPS existentes;
        // usa domínio externo configurado, sem trocar o APP_URL local.
        $appUrl = app(CampaignUnsubscribeLink::class)->base() ?? '';
        $host = parse_url($appUrl, PHP_URL_HOST);
        if (parse_url($appUrl, PHP_URL_SCHEME) !== 'https'
            || ! is_string($host)
            || in_array(mb_strtolower($host), ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            throw ValidationException::withMessages(['campaign' => 'Configure APP_URL com HTTPS público para garantir o funcionamento do descadastro.']);
        }
        if ($campaign->status !== 'draft') {
            throw ValidationException::withMessages(['campaign' => 'Somente rascunhos podem iniciar novos disparos.']);
        }
        if (! $campaign->recipients()->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['campaign' => 'Não há destinatários habilitados.']);
        }

        $campaign->forceFill([
            'status' => 'running', 'approved_by' => $user->id,
            'approved_at' => now(), 'started_at' => now(),
        ])->save();
    }

    public function pause(User $user, EmailCampaign $campaign): void
    {
        abort_unless($user->isCommercialManager(), 403);

        DB::transaction(static function () use ($campaign): void {
            $locked = EmailCampaign::query()->whereKey($campaign->getKey())
                ->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'running') {
                return;
            }

            $locked->update(['status' => 'paused']);

            // Libera apenas tentativas NÃO iniciadas. UUID antigo deixa de
            // valer, então um job atrasado não pode enviar após retomar.
            EmailCampaignRecipient::query()
                ->where('email_campaign_id', $locked->id)
                ->where('status', 'reserved')
                ->update([
                    'status' => 'pending',
                    'reserved_at' => null,
                    'reservation_token' => null,
                ]);
        });
    }

    public function resume(User $user, EmailCampaign $campaign): void
    {
        abort_unless($user->isCommercialManager(), 403);

        if ($campaign->status === 'paused') {
            if (! config('campaign_mail.enabled') || ! config('campaign_mail.live_enabled')) {
                throw ValidationException::withMessages(['campaign' => 'Envios reais estão desabilitados.']);
            }
            // Condição atômica: não reabre campanha cancelada por outra sessão.
            EmailCampaign::query()->whereKey($campaign->getKey())
                ->where('status', 'paused')
                ->update(['status' => 'running']);
        }
    }

    public function cancel(User $user, EmailCampaign $campaign): void
    {
        abort_unless($user->isCommercialManager(), 403);

        DB::transaction(static function () use ($campaign): void {
            $locked = EmailCampaign::query()->whereKey($campaign->getKey())
                ->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->status, ['draft', 'running', 'paused'], true)) {
                return;
            }

            $locked->update(['status' => 'canceled']);
            EmailCampaignRecipient::query()
                ->where('email_campaign_id', $locked->id)
                ->whereIn('status', ['pending', 'reserved'])
                ->update([
                    'status' => 'canceled',
                    'reservation_token' => null,
                ]);
            // 'sending' é tentativa já iniciada: não sobrescrever nem
            // pressupor que o SMTP pode desfazer a mensagem.
        });
    }

    /** Envio de teste é sempre forçado ao endereço configurado, nunca aos contatos selecionados. */
    public function sendTest(User $user, string $subject, string $body): void
    {
        abort_unless($user->isCommercialManager(), 403);
        if (! config('campaign_mail.enabled')) {
            throw ValidationException::withMessages(['campaign' => 'Habilite CAMPAIGN_MAIL_ENABLED=true para testar o SMTP.']);
        }
        $to = (string) config('campaign_mail.test_to');
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['campaign' => 'Configure CAMPAIGN_MAIL_TEST_TO com um e-mail autorizado.']);
        }
        $key = 'campaigns-test:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['campaign' => 'Limite de três testes por hora.']);
        }
        RateLimiter::hit($key, 3600);

        $person = new EmailCampaignRecipient([
            'contact_name' => $user->name,
            'company_name' => 'ExportControl',
            'job_title' => '',
            'email' => $to,
        ]);
        $subject = app(CampaignContent::class)->render($subject, $person);
        $body = app(CampaignContent::class)->render($body, $person);
        Mail::mailer((string) config('campaign_mail.mailer', 'smtp'))
            ->to($to)
            ->send(new ProspectorCampaignMessage('[TESTE] '.$subject, $body, null, null, true));
    }

    public function suppress(string $email, string $reason = 'unsubscribe'): void
    {
        $normal = CampaignAudience::normalizedEmail($email);
        if (filter_var($normal, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        DB::transaction(static function () use ($normal, $reason): void {
            EmailCampaignSuppression::query()->firstOrCreate(
                ['normalized_email' => $normal], ['reason' => $reason],
            );
            EmailCampaignRecipient::query()
                ->where('normalized_email', $normal)
                ->whereIn('status', ['pending', 'reserved'])
                ->update(['status' => 'suppressed', 'reservation_token' => null]);
        });
    }
}
