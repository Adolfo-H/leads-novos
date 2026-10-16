<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Relatorio DSN vinculado a um Message-ID previamente enviado pelo Prospector. */
class EmailCampaignBounce extends Model
{
    protected $fillable = [
        'email_campaign_id', 'email_campaign_recipient_id', 'email_campaign_test_message_id',
        'mailbox', 'uid_validity', 'imap_uid', 'final_recipient', 'status_code',
        'action', 'classification', 'diagnostic', 'received_at',
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
