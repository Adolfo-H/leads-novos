<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignRecipient;
use App\Models\EmailCampaignReply;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A confirmation deliberately entered by a commercial manager after reading
 * the actual message in Outlook. Never infers human intent from a header.
 * Does not send mail, contact a CRM or load third-party content.
 */
final class CampaignInboundReplyReview
{
    public function confirm(int $noticeId, int $managerId): bool
    {
        if ($noticeId < 1 || $managerId < 1
            || ! Schema::hasColumn('email_campaign_inbound_notices', 'promoted_to_reply_at')) {
            return false;
        }

        $manager = User::query()->find($managerId);
        if ($manager === null || ! $manager->isCommercialManager()) {
            return false;
        }

        return DB::transaction(function () use ($noticeId, $managerId): bool {
            $notice = DB::table('email_campaign_inbound_notices')
                ->where('id', $noticeId)->lockForUpdate()->first();

            if ($notice === null
                || $notice->promoted_to_reply_at !== null
                || (string) $notice->notice_type !== 'reply_needs_review'
                || ! in_array((string) $notice->link_method, ['sender_only', 'reference_exact'], true)
                || (bool) $notice->requires_review === false) {
                return false;
            }

            if (CampaignInboundNoticeFilter::isTechnical([
                'from_email' => $notice->from_email,
                'subject' => $notice->subject,
            ])) {
                return false;
            }

            $recipient = EmailCampaignRecipient::query()
                ->whereKey((int) $notice->email_campaign_recipient_id)
                ->lockForUpdate()->first();
            if ($recipient === null
                || (int) $recipient->email_campaign_id !== (int) $notice->email_campaign_id
                || $recipient->sent_at === null
                || $recipient->attempted_at === null
                || CampaignAudience::normalizedEmail((string) $notice->from_email)
                    !== (string) $recipient->normalized_email) {
                return false;
            }

            // Without original Message-ID proof, never promote an older or
            // unrelated message, even after a UI confirmation.
            if ((string) $notice->link_method === 'sender_only') {
                try {
                    $sent = CarbonImmutable::parse($recipient->sent_at)->setTimezone('UTC');
                    $received = CarbonImmutable::parse((string) $notice->received_at)->setTimezone('UTC');
                } catch (\Throwable) {
                    return false;
                }
                if ($received->lessThan($sent) || $received->greaterThan($sent->addHours(72))) {
                    return false;
                }
            }

            if (EmailCampaignReply::query()
                ->where('mailbox', $notice->mailbox)
                ->where('uid_validity', $notice->uid_validity)
                ->where('imap_uid', $notice->imap_uid)->exists()) {
                return false;
            }

            $reply = EmailCampaignReply::query()->create([
                'email_campaign_id' => (int) $notice->email_campaign_id,
                'email_campaign_recipient_id' => (int) $recipient->id,
                'mailbox' => (string) $notice->mailbox,
                'uid_validity' => (string) $notice->uid_validity,
                'imap_uid' => (int) $notice->imap_uid,
                'from_email' => (string) $notice->from_email,
                'subject' => (string) ($notice->subject ?? ''),
                // Message-ID incoming was not captured on a header-only notice.
                'received_at' => $notice->received_at,
            ]);

            DB::table('email_campaign_inbound_notices')->where('id', $noticeId)->update([
                'promoted_to_reply_at' => now('UTC'),
                'promoted_by_user_id' => $managerId,
                'promoted_reply_id' => (int) $reply->id,
                'updated_at' => now('UTC'),
            ]);

            if ($recipient->replied_at === null) {
                // This also prevents automatic follow-ups to confirmed responders.
                $recipient->update(['replied_at' => $reply->received_at ?? now('UTC')]);
            }

            return true;
        });
    }
}
