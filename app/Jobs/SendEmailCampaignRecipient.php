<?php

namespace App\Jobs;

use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaignRecipient;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\UnsubscribeToken;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Não há retry automático: a conexão pode cair APÓS o SMTP ter aceitado a mensagem.
 * Reenvio de resultado incerto requer avaliação humana para evitar duplicidade.
 */
class SendEmailCampaignRecipient implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $recipientId) {}

    public function handle(
        CampaignDispatcher $dispatcher,
        CampaignContent $renderer,
        UnsubscribeToken $tokens,
    ): void {
        // Verificação no momento do envio, inclusive se o job foi reservado antes.
        /** @var EmailCampaignRecipient|null $recipient */
        $recipient = DB::transaction(function () use ($dispatcher): ?EmailCampaignRecipient {
            $row = EmailCampaignRecipient::query()->with('campaign')
                ->lockForUpdate()->find($this->recipientId);
            if ($row === null || $row->status !== 'reserved') {
                return null;
            }
            if (! config('campaign_mail.enabled') || ! config('campaign_mail.live_enabled')) {
                $row->update(['status' => 'pending', 'reserved_at' => null]);

                return null;
            }
            $campaign = $row->campaign;
            if ($campaign === null || $campaign->status !== 'running') {
                $row->update([
                    'status' => $campaign?->status === 'canceled' ? 'canceled' : 'pending',
                    'reserved_at' => null,
                ]);

                return null;
            }
            if ($dispatcher->markAsSuppressedIfNecessary($row)) {
                return null;
            }
            $row->update(['status' => 'sending', 'attempted_at' => now(), 'attempts' => $row->attempts + 1]);

            return $row;
        });
        if ($recipient === null || $recipient->campaign === null) {
            return;
        }

        try {
            $campaign = $recipient->campaign;
            // O Message-ID é persistido ANTES da entrega para correlacionar a resposta.
            $domain = parse_url('https://'.(string) config('campaign_mail.from_address'), PHP_URL_HOST);
            $domain = is_string($domain) && $domain !== '' ? $domain : 'exportcontrol.com.br';
            $msgId = (string) Str::uuid().'@'.$domain;
            $recipient->update(['message_id' => $msgId]);
            $unsubscribe = route('campaigns.unsubscribe', ['token' => $tokens->forRecipient($recipient)]);
            $subject = $renderer->render($campaign->subject, $recipient);
            $body = $renderer->render($campaign->body, $recipient);

            Mail::mailer((string) config('campaign_mail.mailer', 'smtp'))
                ->to($recipient->email)
                ->send(new ProspectorCampaignMessage($subject, $body, $unsubscribe, $msgId));
            $recipient->update(['status' => 'sent', 'sent_at' => now(), 'error_message' => null]);
        } catch (Throwable $exception) {
            report($exception);
            // A exceção pode ocorrer mesmo após o SMTP aceitar: sem retry automático.
            $recipient->update([
                'status' => 'uncertain',
                'error_message' => mb_substr(class_basename($exception).': envio não confirmado; verifique o servidor.', 0, 255),
            ]);
        }
    }
}
