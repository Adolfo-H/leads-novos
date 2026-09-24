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
        'source',
        'source_object_type',
        'source_object_id',
        'title',
        'description',
        'metadata',
        'occurred_at',
        'source_updated_at',
        'is_deleted',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',

            'occurred_at' => 'datetime',

            'source_updated_at' => 'datetime',

            'is_deleted' => 'boolean',
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
