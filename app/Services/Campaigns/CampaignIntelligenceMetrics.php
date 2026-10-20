<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Indicadores observados; não atribui causalidade a negócios HubSpot. */
final class CampaignIntelligenceMetrics
{
    /** @return array<string, int> */
    public function summary(): array
    {
        $since = now('UTC')->subDays(90);
        $counts = [
            'campaigns' => DB::table('email_campaigns')->where('created_at', '>=', $since)->count(),
            'smtp_accepted' => DB::table('email_campaign_recipients')->where('status', 'sent')->where('sent_at', '>=', $since)->count(),
            'responses' => DB::table('email_campaign_replies')->where('received_at', '>=', $since)->count(),
            'bounces' => DB::table('email_campaign_bounces')->where('received_at', '>=', $since)->count(),
            'unsubscribes' => DB::table('email_campaign_suppressions')->where('reason', 'unsubscribe')->where('created_at', '>=', $since)->count(),
            'contacts_with_hubspot_deals' => 0,
        ];
        if (Schema::hasTable('hubspot_deal_contact') && Schema::hasTable('hubspot_contacts')) {
            $counts['contacts_with_hubspot_deals'] = DB::table('email_campaign_recipients as r')
                ->join('hubspot_contacts as h', static function ($join): void {
                    $join->whereRaw("r.source_key = ('hubspot:' || CAST(h.hubspot_id AS VARCHAR))");
                })
                ->join('hubspot_deal_contact as rel', 'rel.hubspot_contact_id', '=', 'h.id')
                ->where('r.created_at', '>=', $since)
                ->distinct()->count('r.id');
        }

        return $counts;
    }
}
