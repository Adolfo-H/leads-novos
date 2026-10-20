<?php

namespace App\Jobs;

use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignSendPolicy;
use App\Services\Campaigns\CampaignSequenceGuard;
use App\Services\Campaigns\CampaignUnsubscribeLink;
use App\Services\Campaigns\UnsubscribeToken;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Envio único. SMTP não oferece garantia de exatamente uma entrega:
 * qualquer falha após o aceite permanece 'uncertain', sem retry automático.
 * Jobs velhos não podem aproveitar reservas novas do mesmo destinatário.
 */
class SendEmailCampaignRecipient implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    // Nullable apenas para compatibilidade com jobs legados/testes anteriores.
    // Toda reserva nova gera UUID obrigatório e exige correspondência exata.
    public function __construct(public int $recipientId, public ?string $reservationToken = null) {}

    public function handle(
        CampaignDispatcher $dispatcher,
        CampaignContent $renderer,
        UnsubscribeToken $tokens,
    ): void {
        /** @var EmailCampaignRecipient|null $recipient */
        $recipient = DB::transaction(function () use ($dispatcher): ?EmailCampaignRecipient {
            $gate = DB::table('email_campaign_dispatch_gates')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();
            if ($gate === null) {
                throw new LogicException('Campaign dispatch quota gate is missing.');
            }

            $candidate = EmailCampaignRecipient::query()->find($this->recipientId);
            if ($candidate === null) {
                return null;
            }

            // The global gate serializes quota checks before campaign/recipient locks.
            $campaign = EmailCampaign::query()
                ->whereKey($candidate->email_campaign_id)
                ->lockForUpdate()->first();
            $row = EmailCampaignRecipient::query()
                ->whereKey($candidate->id)
                ->lockForUpdate()->first();

            if ($row === null || $row->status !== 'reserved') {
                return null;
            }
            if ($this->reservationToken === null || $this->reservationToken === ''
                || $row->reservation_token === null || $row->reservation_token !== $this->reservationToken) {
                return null;
            }
            if (! config('campaign_mail.enabled') || ! config('campaign_mail.live_enabled')) {
                $row->update([
                    'status' => 'pending',
                    'reserved_at' => null,
                    'reservation_token' => null,
                ]);

                return null;
            }
            if ($campaign === null || $campaign->status !== 'running') {
                $row->update([
                    'status' => $campaign?->status === 'canceled' ? 'canceled' : 'pending',
                    'reserved_at' => null,
                    'reservation_token' => null,
                ]);

                return null;
            }
            // EC_CAMPAIGN_INTELLIGENCE_V9: verifica resposta/DSN/opt-out também no instante do job.
            if (app(CampaignSequenceGuard::class)->suppressIfAnswered($row)) {
                return null;
            }
            if ($dispatcher->markAsSuppressedIfNecessary($row)) {
                return null;
            }

            // EC_CAMPAIGNS_V7_PUBLIC_URL: não inicia SMTP se o descadastro público
            // estiver ausente/inválido. Mantém destinatário pendente para revisão.
            if (! app(CampaignUnsubscribeLink::class)->isReady()) {
                $row->update(['status' => 'pending', 'reserved_at' => null, 'reservation_token' => null]);

                return null;
            }

            // CAMPANHAS_V6_SEND_WINDOW: um job reservado no fim da janela
            // nao pode comecar o SMTP fora do horario configurado.
            $sendPolicy = app(CampaignSendPolicy::class);
            $policy = $sendPolicy->effective();
            if (! $sendPolicy->windowOpen($policy)) {
                $row->update(['status' => 'pending', 'reserved_at' => null, 'reservation_token' => null]);

                return null;
            }

            $utcNow = now('UTC');
            $hourLimit = max(1, min(100, $policy['hour']));
            $dayLimit = max(1, min(500, $policy['day']));
            $hourAttempts = EmailCampaignRecipient::query()
                ->whereNotNull('attempted_at')
                ->where('attempted_at', '>=', $utcNow->copy()->subHour())
                ->count();
            $dayAttempts = EmailCampaignRecipient::query()
                ->whereNotNull('attempted_at')
                ->where('attempted_at', '>=', $utcNow->copy()->subDay())
                ->count();
            if ($hourAttempts >= $hourLimit || $dayAttempts >= $dayLimit) {
                $row->update([
                    'status' => 'pending',
                    'reserved_at' => null,
                    'reservation_token' => null,
                ]);

                return null;
            }

            // A partir daqui o envio está EM ANDAMENTO. Pausar/cancelar
            // impede novas reservas, mas não desfaz uma tentativa já iniciada.
            $row->update([
                'status' => 'sending',
                'attempted_at' => $utcNow,
                'attempts' => $row->attempts + 1,
                'reservation_token' => null,
            ]);

            return $row->load('campaign');
        });

        if ($recipient === null || $recipient->campaign === null) {
            return;
        }

        try {
            $campaign = $recipient->campaign;
            $domain = parse_url('https://'.(string) config('campaign_mail.from_address'), PHP_URL_HOST);
            $domain = is_string($domain) && $domain !== '' ? $domain : 'exportcontrol.com.br';
            $msgId = (string) Str::uuid().'@'.$domain;
            $recipient->update(['message_id' => $msgId]);
            $unsubscribe = app(CampaignUnsubscribeLink::class)->forRecipient($recipient, $tokens);
            $subject = $renderer->render($campaign->subject, $recipient);
            $body = $renderer->render($campaign->body, $recipient);

            Mail::mailer((string) config('campaign_mail.mailer', 'smtp'))
                ->to($recipient->email)
                ->send(new ProspectorCampaignMessage($subject, $body, $unsubscribe, $msgId));

            $recipient->update([
                'status' => 'sent',
                'sent_at' => now('UTC'),
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $recipient->update([
                'status' => 'uncertain',
                'error_message' => mb_substr(
                    class_basename($exception).': envio não confirmado; verifique o servidor.',
                    0,
                    255
                ),
            ]);
        }
    }
}
