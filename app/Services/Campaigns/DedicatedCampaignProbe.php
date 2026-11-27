<?php

namespace App\Services\Campaigns;

use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/** One diagnostic message, to the configured internal test address only. */
final class DedicatedCampaignProbe
{
    private const PREFIX = 'ec-dedicated-test-';

    public static function inProgress(): bool
    {
        return EmailCampaignTestMessage::query()->where('message_id', 'like', self::PREFIX.'%')
            ->where('status', 'initiated')->exists();
    }

    public static function attemptsSince(DateTimeInterface $cutoff): int
    {
        return EmailCampaignTestMessage::query()->where('message_id', 'like', self::PREFIX.'%')
            ->where('attempted_at', '>=', $cutoff)->count();
    }

    public function send(User $user, EmailCampaign $campaign, string $key, ?string $requestId = null): void
    {
        abort_unless($user->isCommercialManager(), 403);
        $profile = DualCampaignSenders::profile($key);
        $to = CampaignAudience::normalizedEmail((string) config('campaign_mail.test_to', ''));
        if ($profile === null || filter_var($to, FILTER_VALIDATE_EMAIL) === false
            || in_array($to, ['publicidade@exportcontrol.com.br', 'propaganda@exportcontrol.com.br'], true)) {
            throw ValidationException::withMessages(['campaign' => 'Configure um endereço de teste autorizado, diferente das caixas remetentes.']);
        }

        if ($requestId !== null && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $requestId) !== 1) {
            throw ValidationException::withMessages(['campaign' => 'Identificador de teste inválido. Atualize a página.']);
        }
        $suffix = $requestId === null ? '' : '-'.strtolower($requestId);
        $messageId = self::PREFIX.$campaign->id.'-'.$key.$suffix.'@exportcontrol.com.br';
        $test = DB::transaction(static function () use ($user, $campaign, $to, $messageId): EmailCampaignTestMessage {
            $gate = DB::table('email_campaign_dispatch_gates')->where('id', 1)->lockForUpdate()->first();
            $state = DualCampaignSenders::state();
            if ($gate === null || ! $state['enabled'] || $state['blocked']
                || ! config('campaign_mail.enabled') || ! DualCampaignSenders::ready()) {
                throw ValidationException::withMessages(['campaign' => 'Diagnóstico bloqueado: verifique autorização, bloqueios SMTP e sincronização IMAP.']);
            }
            $locked = EmailCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft' || EmailCampaign::query()->where('status', 'running')->exists()
                || EmailCampaignRecipient::query()->whereIn('status', ['reserved', 'sending'])->exists()
                || self::inProgress()) {
                throw ValidationException::withMessages(['campaign' => 'Use um rascunho e aguarde o encerramento das tentativas em andamento. Não há reenvio de diagnóstico incerto.']);
            }
            if (EmailCampaignTestMessage::query()->where('message_id', $messageId)->exists()) {
                throw ValidationException::withMessages(['campaign' => 'Esta solicitação de teste já foi registrada. Confira o resultado; para um novo teste, atualize a página.']);
            }
            $now = now('UTC');
            // The additional fixed two/hour ceiling is removed for internal diagnostics only.
            // The following approved GLOBAL rolling quotas, serialization and hold remain mandatory.
            $limits = app(CampaignSendPolicy::class)->effective();
            foreach (['tick' => $now->copy()->subMinute(), 'hour' => $now->copy()->subHour(), 'day' => $now->copy()->subDay()] as $unit => $cutoff) {
                $used = EmailCampaignRecipient::query()->where('attempted_at', '>=', $cutoff)->count()
                    + self::attemptsSince($cutoff);
                if ($used >= $limits[$unit]) {
                    throw ValidationException::withMessages(['campaign' => 'Cota global sem capacidade para o diagnóstico neste momento.']);
                }
            }

            return EmailCampaignTestMessage::query()->create([
                'email_campaign_id' => $locked->id,
                'test_to' => $to,
                'message_id' => $messageId,
                'status' => 'initiated',
                'attempted_at' => $now,
                'created_by' => $user->id,
            ]);
        });

        try {
            Mail::mailer(DualCampaignSenders::mailer($key))->to($to)->send(new ProspectorCampaignMessage(
                '[TESTE INTERNO] SMTP '.$key,
                'Mensagem de diagnóstico da ExportControl. Responda a esta mensagem para verificar o recebimento IMAP. Nenhum destinatário comercial foi utilizado.',
                null, $messageId, true, $profile['address'],
            ));
            $test->update(['status' => 'smtp_accepted', 'sent_at' => now('UTC')]);
        } catch (Throwable $exception) {
            // Never retry or fall back to the other account; never print credentials.
            DualCampaignSenders::halt();
            $test->update(['status' => 'uncertain']);
            throw ValidationException::withMessages(['campaign' => 'Resultado SMTP incerto. As novas tentativas foram bloqueadas; confira o servidor antes de qualquer novo envio.']);
        }
    }
}
