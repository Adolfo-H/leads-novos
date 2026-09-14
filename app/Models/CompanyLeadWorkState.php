<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyLeadWorkState extends Model
{
    protected $fillable = [
        'company_id',
        'assigned_user_id',
        'status',
        'note',
        'last_action_at',
        'next_action_at',
    ];

    protected function casts(): array
    {
        return [
            'last_action_at' => 'datetime',
            'next_action_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_user_id'
        );
    }
}
