<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyCrmCheck extends Model
{
    protected $fillable = [
        'company_id',
        'provider',
        'status',
        'external_id',
        'external_name',
        'external_domain',
        'lifecycle_stage',
        'owner_external_id',
        'contacted_count',
        'associated_deals_count',
        'last_contacted_at',
        'matched_by',
        'matched_value',
        'external_url',
        'metadata',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'contacted_count' => 'integer',

            'associated_deals_count' => 'integer',

            'last_contacted_at' => 'datetime',

            'checked_at' => 'datetime',

            'metadata' => 'array',
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
}
