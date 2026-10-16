<?php

namespace App\Jobs;

use App\Mail\ProspectorCampaignMessage;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignTestMessage;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Campaigns\CampaignContent;
use App\Services\Campaigns\CampaignPilot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Envia uma única mensagem PILOTO para endereço explicitamente autorizado. */
final class SendCampaignPilotMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $testMessageId) {}

    public function handle(CampaignPilot $pilot, CampaignContent $content): void
    {
        /** @var EmailCampaignTestMessage|null $test */
        $test = DB::transaction(function () use ($pilot): ?EmailCampaignTestMessage {
            $candidate = EmailCampaignTestMessage::query()->find($this->testMessageId);
            if ($candidate === null) {
                return null;
            }
            // Trava ordenada: campanha antes do registro individual.
            $campaign = EmailCampaign::query()->whereKey($candidate->email_campaign_id)->lockForUpdate()->first();
            $row = EmailCampaignTestMessage::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if ($row === null || $row->status !== 'pilot_queued') {
                return null;
            }

            // Verificação no worker, não só no clique. Mudanças na allowlist ou
            // ativação comercial invalidam os jobs ainda não iniciados.
            if (! config('campaign_mail.enabled') || ! config('campaign_mail.pilot_enabled')
                || config('campaign_mail.live_enabled') || config('campaign_mail.mailer', 'smtp') !== 'smtp'
                || ! $pilot->isAllowed((string) $row->test_to)
                || ! config('campaign_mail.imap_enabled')
                || CampaignAudience::normalizedEmail((string) config('campaign_mail.reply_to'))
                    !== CampaignAudience::normalizedEmail((string) config('campaign_mail.imap_username'))
                || $campaign === null || $campaign->status !== 'draft') {
                $row->update(['status' => 'pilot_blocked']);

                return null;
            }

            $row->update(['status' => 'pilot_sending', 'attempted_at' => now('UTC')]);

            return $row;
        }, 3);

        if ($test === null) {
            return;
        }

        try {
            $campaign = $test->campaign;
            if ($campaign === null) {
                throw new \RuntimeException('Rascunho indisponível durante o piloto.');
            }
            $creator = User::query()->find($test->created_by);
            $sample = new EmailCampaignRecipient([
                'contact_name' => (string) ($creator?->name ?: 'Equipe ExportControl'),
                'company_name' => 'ExportControl',
                'job_title' => '',
                'email' => (string) $test->test_to,
            ]);
            $subject = $content->render((string) $campaign->subject, $sample);
            $body = $content->render((string) $campaign->body, $sample);

            Mail::mailer('smtp')
                ->to((string) $test->test_to)
                ->send(new ProspectorCampaignMessage(
                    '[PILOTO] '.$subject,
                    $body,
                    null,
                    (string) $test->message_id,
                    true,
                ));

            $test->update(['status' => 'pilot_smtp_accepted', 'sent_at' => now('UTC')]);
        } catch (Throwable $exception) {
            report($exception);
            // Falha após o aceite SMTP pode ser ambígua: NUNCA tentar novamente.
            $test->update(['status' => 'pilot_uncertain']);
        }
    }
}
