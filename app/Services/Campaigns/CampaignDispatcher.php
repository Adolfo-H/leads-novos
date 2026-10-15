<?php

namespace App\Services\Campaigns;

use App\Jobs\SendEmailCampaignRecipient;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignSuppression;
use Illuminate\Support\Facades\DB;

final class CampaignDispatcher
{
    /**
     * Libera somente poucos destinatários por execução, contabilizando
     * reservas (não apenas envios), inclusive quando o worker estiver parado.
     */
    public function tick(): int
    {
        if (! config('campaign_mail.enabled') || ! config('campaign_mail.live_enabled')) {
            return 0;
        }

        $hour = max(1, min(100, (int) config('campaign_mail.per_hour', 10)));
        $day = max(1, min(500, (int) config('campaign_mail.per_day', 30)));
        $tick = max(1, min(5, (int) config('campaign_mail.per_tick', 1)));

        /** @var list<int> $reserved */
        $reserved = DB::transaction(static function () use ($hour, $day, $tick): array {
            // Uma única lock de banco evita duas execuções simultâneas no mesmo servidor.
            $campaign = EmailCampaign::query()
                ->where('status', 'running')->orderBy('id')->lockForUpdate()->first();
            if ($campaign === null) {
                return [];
            }

            $hourUsed = EmailCampaignRecipient::query()->where('reserved_at', '>=', now()->subHour())->count();
            $dayUsed = EmailCampaignRecipient::query()->where('reserved_at', '>=', now()->startOfDay())->count();
            $available = min($tick, max(0, $hour - $hourUsed), max(0, $day - $dayUsed));
            if ($available === 0) {
                return [];
            }

            $ids = EmailCampaignRecipient::query()
                ->where('email_campaign_id', $campaign->id)
                ->where('status', 'pending')
                ->orderBy('id')->limit($available)
                ->lockForUpdate()->pluck('id')->all();
            $result = [];
            foreach ($ids as $id) {
                $updated = EmailCampaignRecipient::query()
                    ->whereKey((int) $id)->where('status', 'pending')
                    ->update(['status' => 'reserved', 'reserved_at' => now()]);
                if ($updated > 0) {
                    $result[] = (int) $id;
                }
            }

            return $result;
        });

        foreach ($reserved as $id) {
            SendEmailCampaignRecipient::dispatch($id)
                ->onQueue((string) config('campaign_mail.queue', 'campaigns'));
        }

        // Campanhas sem trabalho pendente não devem ficar eternamente "em andamento".
        EmailCampaign::query()->where('status', 'running')->get()->each(
            static function (EmailCampaign $campaign): void {
                if (! EmailCampaignRecipient::query()
                    ->where('email_campaign_id', $campaign->id)
                    ->whereIn('status', ['pending', 'reserved', 'sending'])->exists()) {
                    $campaign->update(['status' => 'completed', 'completed_at' => now()]);
                }
            }
        );

        return count($reserved);
    }

    public function markAsSuppressedIfNecessary(EmailCampaignRecipient $recipient): bool
    {
        $blocked = EmailCampaignSuppression::query()
            ->where('normalized_email', $recipient->normalized_email)->exists();
        if ($blocked) {
            $recipient->update(['status' => 'suppressed']);
        }

        return $blocked;
    }
}
