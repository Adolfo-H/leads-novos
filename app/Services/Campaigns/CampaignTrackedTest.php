<?php

namespace App\Services\Campaigns;

use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignInboxCursor;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Envia SOMENTE para CAMPAIGN_TRACKED_TEST_TO, nunca para destinatários de campanhas.
 * O registro fica separado para não adulterar métricas comerciais.
 */
final class CampaignTrackedTest
{
    public function send(User $user, EmailCampaign $campaign): EmailCampaignTestMessage
    {
        abort_unless($user->isCommercialManager(), 403);

        if ($campaign->status !== 'draft') {
            throw ValidationException::withMessages([
                'campaign' => 'O teste rastreado requer uma campanha salva como rascunho.',
            ]);
        }
        if (! config('campaign_mail.enabled') || ! config('campaign_mail.imap_enabled')) {
            throw ValidationException::withMessages([
                'campaign' => 'SMTP e IMAP precisam estar habilitados para este teste.',
            ]);
        }

        $to = CampaignAudience::normalizedEmail((string) config('campaign_mail.tracked_test_to', ''));
        $imap = CampaignAudience::normalizedEmail((string) config('campaign_mail.imap_username', ''));
        $replyTo = CampaignAudience::normalizedEmail((string) config('campaign_mail.reply_to', ''));
        $from = CampaignAudience::normalizedEmail((string) config('campaign_mail.from_address', ''));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false
            || filter_var($imap, FILTER_VALIDATE_EMAIL) === false
            || $replyTo !== $imap || $to === $imap || $to === $from) {
            throw ValidationException::withMessages([
                'campaign' => 'Defina CAMPAIGN_TRACKED_TEST_TO com uma segunda caixa autorizada, diferente da caixa IMAP. O Reply-To deve coincidir com o IMAP.',
            ]);
        }

        $initialized = EmailCampaignInboxCursor::query()
            ->where('mailbox', (string) config('campaign_mail.imap_username'))
            ->whereNotNull('uid_validity')
            ->exists();
        if (! $initialized) {
            throw ValidationException::withMessages([
                'campaign' => 'Inicialize o cursor IMAP com campaigns:sync-replies antes de enviar o teste.',
            ]);
        }

        $limiter = 'campaigns-tracked-test:'.$user->id;
        if (RateLimiter::tooManyAttempts($limiter, 3)) {
            throw ValidationException::withMessages([
                'campaign' => 'Limite de três testes rastreados por hora atingido.',
            ]);
        }
        RateLimiter::hit($limiter, 3600);

        $parts = explode('@', $from);
        $domain = $parts[1] ?? 'exportcontrol.com.br';
        $messageId = (string) Str::uuid().'@'.$domain;

        // Persiste antes do SMTP para nunca perder o identificador em caso de falha.
        $test = EmailCampaignTestMessage::query()->create([
            'email_campaign_id' => $campaign->id,
            'test_to' => $to,
            'message_id' => $messageId,
            'status' => 'initiated',
            'attempted_at' => now(),
            'created_by' => $user->id,
        ]);

        $example = new EmailCampaignRecipient([
            'contact_name' => $user->name,
            'company_name' => 'ExportControl',
            'job_title' => '',
            'email' => $to,
        ]);
        $renderer = app(CampaignContent::class);
        $subject = $renderer->render((string) $campaign->subject, $example);
        $body = $renderer->render((string) $campaign->body, $example);

        try {
            Mail::mailer((string) config('campaign_mail.mailer', 'smtp'))
                ->to($to)
                ->send(new ProspectorCampaignMessage(
                    '[TESTE RASTREADO] '.$subject, $body, null, $messageId, true,
                ));
            $test->update(['status' => 'smtp_accepted', 'sent_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);
            // Uma exceção pode ocorrer após aceitação SMTP; não repetimos automaticamente.
            $test->update(['status' => 'uncertain']);
            throw ValidationException::withMessages([
                'campaign' => 'O SMTP não confirmou o envio do teste. Verifique a caixa e os logs antes de tentar novamente.',
            ]);
        }

        return $test->refresh();
    }
}
