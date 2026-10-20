<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Trava final no worker: resposta, bounce ou opt-out após criar rascunho impedem o follow-up. */
final class CampaignSequenceGuard
{
    public function suppressIfAnswered(EmailCampaignRecipient $candidate): bool
    {
        if (! Schema::hasTable('email_campaign_sequence_links')) {
            return false;
        }
        $link = DB::table('email_campaign_sequence_links')
            ->where('followup_recipient_id', $candidate->id)->first();
        if ($link === null) {
            return false;
        }
        $original = EmailCampaignRecipient::query()->find((int) $link->original_recipient_id);
        $ineligible = $original === null || $original->status !== 'sent'
            || $original->replied_at !== null
            || DB::table('email_campaign_replies')->where('email_campaign_recipient_id', $link->original_recipient_id)->exists()
            || DB::table('email_campaign_bounces')->where('email_campaign_recipient_id', $link->original_recipient_id)->exists()
            || DB::table('email_campaign_suppressions')->where('normalized_email', $candidate->normalized_email)->exists();
        if (! $ineligible) {
            return false;
        }
        $candidate->update(['status' => 'suppressed', 'reserved_at' => null, 'reservation_token' => null]);

        return true;
    }
}
