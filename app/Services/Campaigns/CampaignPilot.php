<?php

namespace App\Services\Campaigns;

use App\Jobs\SendCampaignPilotMessage;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Piloto isolado das campanhas comerciais: lista EXATA de caixas autorizadas.
 * Nenhum destinatário comercial ou registro email_campaign_recipients é usado.
 */
final class CampaignPilot
{
    /** @return list<string> */
    public function allowedTargets(): array
    {
        $raw = trim((string) config('campaign_mail.pilot_allowlist', ''));
        if ($raw === '') {
            return [];
        }

        $parts = explode(',', $raw);
        if (count($parts) > 3) {
            throw ValidationException::withMessages([
                'campaign' => 'O piloto permite no máximo três endereços completos.',
            ]);
        }

        $targets = [];
        foreach ($parts as $part) {
            $email = CampaignAudience::normalizedEmail($part);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_contains($email, '*')) {
                throw ValidationException::withMessages([
                    'campaign' => 'Confira CAMPAIGN_PILOT_ALLOWLIST: use apenas endereços completos, separados por vírgulas.',
                ]);
            }
            $targets[] = $email;
        }

        return array_values(array_unique($targets));
    }

    public function isAllowed(string $email): bool
    {
        return in_array(CampaignAudience::normalizedEmail($email), $this->allowedTargets(), true);
    }

    /**
     * Cria testes rastreados e despacha-os no Redis. Não envia SMTP nesta chamada.
     * Cliques repetidos não criam um segundo piloto para a mesma campanha/endereço.
     */
    public function queue(User $user, EmailCampaign $campaign): int
    {
        abort_unless($user->isCommercialManager(), 403);
        $this->assertSafePilot();

        $targets = $this->allowedTargets();
        if ($targets === []) {
            throw ValidationException::withMessages([
                'campaign' => 'Configure CAMPAIGN_PILOT_ALLOWLIST com uma ou mais caixas autorizadas.',
            ]);
        }

        $imap = CampaignAudience::normalizedEmail((string) config('campaign_mail.imap_username', ''));
        $replyTo = CampaignAudience::normalizedEmail((string) config('campaign_mail.reply_to', ''));
        $from = CampaignAudience::normalizedEmail((string) config('campaign_mail.from_address', ''));
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false
            || filter_var($imap, FILTER_VALIDATE_EMAIL) === false
            || $replyTo !== $imap
            || ! config('campaign_mail.imap_enabled')) {
            throw ValidationException::withMessages([
                'campaign' => 'O IMAP deve estar ativo e a caixa Reply-To deve corresponder à caixa monitorada.',
            ]);
        }

        $initialized = EmailCampaignInboxCursor::query()
            ->where('mailbox', (string) config('campaign_mail.imap_username'))
            ->whereNotNull('uid_validity')
            ->exists();
        if (! $initialized) {
            throw ValidationException::withMessages([
                'campaign' => 'Execute campaigns:sync-replies para inicializar o IMAP antes do piloto.',
            ]);
        }

        /** @var list<int> $created */
        $created = DB::transaction(static function () use ($campaign, $user, $targets, $from): array {
            // Mesmo bloqueio global da V4: limite único para pilotos concorrentes.
            $gate = DB::table('email_campaign_dispatch_gates')->where('id', 1)->lockForUpdate()->first();
            if ($gate === null) {
                throw ValidationException::withMessages(['campaign' => 'Execute as migrations da V4 antes do piloto.']);
            }
            $draft = EmailCampaign::query()->whereKey((int) $campaign->id)->lockForUpdate()->first();
            if ($draft === null || $draft->status !== 'draft') {
                throw ValidationException::withMessages(['campaign' => 'O piloto aceita somente campanhas em rascunho.']);
            }

            $newTargets = [];
            foreach ($targets as $email) {
                $already = EmailCampaignTestMessage::query()
                    ->where('email_campaign_id', $draft->id)
                    ->where('test_to', $email)
                    ->where('status', 'like', 'pilot_%')
                    ->exists();
                if (! $already) {
                    $newTargets[] = $email;
                }
            }

            // Os registros novos sao gravados em UTC. Tambem aceitamos a janela
            // legada (horario da aplicacao) para nao ignorar pilotos antigos.
            // O intervalo superior impede que as duas janelas se confundam.
            $utcNow = now('UTC');
            $localNow = $utcNow->copy()->setTimezone((string) config('app.timezone', 'UTC'));
            $recentCount = EmailCampaignTestMessage::query()
                ->where('status', 'like', 'pilot_%')
                ->where(static function ($query) use ($utcNow, $localNow): void {
                    $query->whereBetween('created_at', [$utcNow->copy()->subHour(), $utcNow])
                        ->orWhere(static function ($legacy) use ($localNow): void {
                            // IDs anteriores tinham somente UUID; novos usam prefixo utc-.
                            // Impede contar novamente uma mensagem UTC tres horas depois.
                            $legacy->where('message_id', 'not like', 'utc-%')
                                ->whereBetween('created_at', [$localNow->copy()->subHour(), $localNow]);
                        });
                })
                ->count();
            if ($recentCount + count($newTargets) > 6) {
                throw ValidationException::withMessages([
                    'campaign' => 'Limite de segurança do piloto: seis mensagens a cada 60 minutos.',
                ]);
            }

            $fromParts = explode('@', $from, 2);
            $domain = $fromParts[1] ?? 'exportcontrol.com.br';
            $ids = [];
            foreach ($newTargets as $email) {
                $row = new EmailCampaignTestMessage([
                    'email_campaign_id' => $draft->id,
                    'test_to' => $email,
                    'message_id' => 'utc-'.(string) Str::uuid().'@'.$domain,
                    'status' => 'pilot_queued',
                    'created_by' => $user->id,
                ]);
                // O Eloquent gravaria created_at no fuso padrao de Sao Paulo.
                // Sem UTC explicito, o PostgreSQL interpretaria o horario como UTC.
                $row->created_at = $utcNow;
                $row->updated_at = $utcNow;
                $row->save();
                $ids[] = (int) $row->id;
            }

            return $ids;
        }, 3);

        foreach ($created as $id) {
            try {
                SendCampaignPilotMessage::dispatch($id)
                    ->onConnection('redis')
                    ->onQueue((string) config('campaign_mail.queue', 'campaigns'));
            } catch (Throwable $exception) {
                report($exception);
                EmailCampaignTestMessage::query()->whereKey($id)->where('status', 'pilot_queued')
                    ->update(['status' => 'pilot_queue_error']);
                throw ValidationException::withMessages([
                    'campaign' => 'Não foi possível adicionar todos os testes à fila Redis. Consulte o histórico antes de tentar novamente.',
                ]);
            }
        }

        return count($created);
    }

    public function assertSafePilot(): void
    {
        if (! config('campaign_mail.enabled') || ! config('campaign_mail.pilot_enabled')
            || config('campaign_mail.live_enabled')) {
            throw ValidationException::withMessages([
                'campaign' => 'Piloto indisponível. Exige CAMPAIGN_PILOT_ENABLED=true e envios comerciais bloqueados.',
            ]);
        }
        if (config('campaign_mail.mailer', 'smtp') !== 'smtp') {
            throw ValidationException::withMessages([
                'campaign' => 'O piloto exige o mailer SMTP configurado.',
            ]);
        }
    }
}
