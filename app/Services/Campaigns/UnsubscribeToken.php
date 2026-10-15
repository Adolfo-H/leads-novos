<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignRecipient;

final class UnsubscribeToken
{
    public function forRecipient(EmailCampaignRecipient $recipient): string
    {
        return $recipient->getKey().'.'.$this->signature($recipient);
    }

    public function resolve(string $token): ?EmailCampaignRecipient
    {
        if (preg_match('/^([1-9][0-9]*)\.([a-f0-9]{64})$/D', $token, $parts) !== 1) {
            return null;
        }

        $recipient = EmailCampaignRecipient::query()->find((int) $parts[1]);
        if ($recipient === null || ! hash_equals($this->signature($recipient), $parts[2])) {
            return null;
        }

        return $recipient;
    }

    private function signature(EmailCampaignRecipient $recipient): string
    {
        return hash_hmac('sha256', $recipient->getKey().'|'.$recipient->normalized_email, (string) config('app.key'));
    }
}
