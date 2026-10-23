<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $invitation_expires_at
 */
class AccountAccessRequest extends Model
{
    protected $fillable = [
        'name', 'email', 'company', 'phone', 'job_title', 'status', 'consent_at',
        'reviewed_by', 'reviewed_at', 'review_note', 'invitation_token_hash',
        'invitation_expires_at', 'activated_at', 'notification_status',
        'notification_attempted_at', 'invitation_status', 'invitation_attempted_at',
    ];

    protected $hidden = ['invitation_token_hash'];

    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'activated_at' => 'datetime',
            'notification_attempted_at' => 'datetime',
            'invitation_attempted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
