<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCampaignReply extends Model
{
    protected $fillable = [
        'email_campaign_id', 'email_campaign_recipient_id', 'mailbox',
        'uid_validity', 'imap_uid', 'from_email', 'subject', 'received_at',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    /** @return BelongsTo<EmailCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class);
    }

    /** @return BelongsTo<EmailCampaignRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(EmailCampaignRecipient::class, 'email_campaign_recipient_id');
    }
}
