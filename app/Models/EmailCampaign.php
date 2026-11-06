<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailCampaign extends Model
{
    protected $fillable = [
        'name', 'subject', 'body', 'status', 'from_address', 'from_name',
        'reply_to', 'created_by', 'approved_by', 'approved_at',
        'test_sent_at', 'started_at', 'completed_at',
        'followup_root_campaign_id', 'followup_round',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'test_sent_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<EmailCampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(EmailCampaignRecipient::class);
    }

    /** @return HasMany<EmailCampaign, $this> */
    public function followupRounds(): HasMany
    {
        // Rodadas continuam registros reais para preservar SMTP, respostas e DSN.
        // A interface as apresenta dentro da campanha original.
        return $this->hasMany(self::class, 'followup_root_campaign_id')
            ->orderBy('followup_round')->orderBy('id');
    }

    /** @return HasMany<EmailCampaignReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(EmailCampaignReply::class);
    }

    /** @return HasMany<EmailCampaignBounce, $this> */
    public function bounces(): HasMany
    {
        return $this->hasMany(EmailCampaignBounce::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
