<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** Cadência por campanha; apenas limita a fila, nunca amplia o teto SMTP global. */
final class CampaignPacing
{
    public const AUTOMATIC = 0;

    /** Use global quotas for a draft only. Never retune a running campaign. */
    public function useGlobal(EmailCampaign $campaign): void
    {
        DB::transaction(static function () use ($campaign): void {
            $gate = DB::table('email_campaign_dispatch_gates')->where('id', 1)->lockForUpdate()->first();
            if ($gate === null) {
                throw new \LogicException('Campaign dispatch quota gate is missing.');
            }
            $draft = EmailCampaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            if ($draft->status !== 'draft') {
                throw new InvalidArgumentException('Somente rascunhos podem alterar a cadência.');
            }
            DB::table('email_campaign_pacing_v90')->where('email_campaign_id', $draft->getKey())->delete();
        });
    }

    /** Preserve the chosen pace when reopening an existing draft. */
    public function plannedHours(int $campaignId): int
    {
        if (! Schema::hasTable('email_campaign_pacing_v90')) {
            return self::AUTOMATIC;
        }
        $hours = (int) DB::table('email_campaign_pacing_v90')->where('email_campaign_id', $campaignId)->value('duration_hours');

        return in_array($hours, [1, 2, 4, 8, 12, 24], true) ? $hours : self::AUTOMATIC;
    }

    public function save(EmailCampaign $campaign, int $hours): void
    {
        if (! in_array($hours, [1, 2, 4, 8, 12, 24], true)) {
            throw new InvalidArgumentException('Escolha uma duração de 1, 2, 4, 8, 12 ou 24 horas.');
        }
        $total = (int) $campaign->recipients()->where('status', '<>', 'suppressed')->count();
        $hour = (int) ceil($total / $hours);
        $tick = (int) ceil($total / ($hours * 60));
        // Guarda limites solicitados (nunca garante o prazo). Dispatcher cruza com
        // os tetos globais e as demais campanhas antes de reservar cada destinatário.
        DB::table('email_campaign_pacing_v90')->upsert([[
            'email_campaign_id' => (int) $campaign->id,
            'duration_hours' => $hours,
            'hourly_limit' => max(1, $hour),
            'per_tick_limit' => max(1, $tick),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]], ['email_campaign_id'], ['duration_hours', 'hourly_limit', 'per_tick_limit', 'updated_at']);
    }

    /** @return array{hour:int,tick:int}|null */
    public function limits(int $campaignId): ?array
    {
        // Durante uma implantação gradual, não derrubar filas antes da migration.
        if (! Schema::hasTable('email_campaign_pacing_v90')) {
            return null;
        }

        $row = DB::table('email_campaign_pacing_v90')
            ->where('email_campaign_id', $campaignId)
            ->first(['hourly_limit', 'per_tick_limit']);
        if ($row === null) {
            return null;
        }

        return ['hour' => max(1, (int) $row->hourly_limit), 'tick' => max(1, (int) $row->per_tick_limit)];
    }
}
