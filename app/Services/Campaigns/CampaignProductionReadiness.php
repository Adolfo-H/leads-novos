<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Relatório somente-leitura: não libera campanhas, não consulta segredos, não dispara e-mails. */
final class CampaignProductionReadiness
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $record = Schema::hasTable('email_campaign_release_reviews')
            ? DB::table('email_campaign_release_reviews')->where('id', 1)->first()
            : null;
        $limits = app(CampaignSendPolicy::class)->effective();
        $publicUrl = app(CampaignUnsubscribeLink::class)->base();
        $bounces = Schema::hasTable('email_campaign_bounces')
            ? DB::table('email_campaign_bounces')->count()
            : 0;
        $hardBounces = Schema::hasTable('email_campaign_bounces')
            ? DB::table('email_campaign_bounces')->where('classification', 'invalid')->count()
            : 0;

        $operatorConfirmed = $record !== null && (bool) $record->smtp_confirmed;
        $quotaCompatible = $operatorConfirmed
            && $limits['hour'] <= (int) $record->smtp_per_hour
            && $limits['day'] <= (int) $record->smtp_per_day;
        $technicalChecks = $publicUrl !== null && ! config('app.debug')
            && $quotaCompatible && (bool) ($record->public_tested ?? false)
            && (bool) ($record->audience_reviewed ?? false)
            && (bool) ($record->bounce_reviewed ?? false);

        return [
            'live' => (bool) config('campaign_mail.live_enabled', false),
            'debug' => (bool) config('app.debug', true),
            'public_url' => $publicUrl,
            'limits' => $limits,
            'record' => $record,
            'smtp_confirmed' => $operatorConfirmed,
            'quota_compatible' => $quotaCompatible,
            'technical_checks' => $technicalChecks,
            'bounces' => $bounces,
            'hard_bounces' => $hardBounces,
            'status' => $technicalChecks ? 'Checklist documental concluído; ainda exige liberação operacional' : 'Pendências de homologação',
        ];
    }
}
