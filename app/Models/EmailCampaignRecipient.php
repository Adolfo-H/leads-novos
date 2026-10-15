<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCampaignRecipient extends Model
{
    protected $fillable = [
        'email_campaign_id', 'source_key', 'email', 'normalized_email',
        'contact_name', 'company_name', 'job_title', 'status', 'attempts',
        'reserved_at', 'attempted_at', 'sent_at', 'replied_at',
        'message_id', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmailCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(EmailCampaign::class, 'email_campaign_id');
    }

    /** @return HasMany<EmailCampaignReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(EmailCampaignReply::class);
    }
}
