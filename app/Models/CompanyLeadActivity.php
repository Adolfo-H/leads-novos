<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyLeadActivity extends Model
{
    protected $fillable = [
        'company_id',
        'user_id',
        'type',
        'title',
        'description',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class
        );
    }
}
