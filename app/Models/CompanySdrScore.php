<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanySdrScore extends Model
{
    protected $fillable = [
        'company_id',
        'score',
        'priority',
        'label',
        'is_eligible',
        'is_provisional',
        'blocked_reason',
        'factors',
        'version',
        'metadata',
        'calculated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',

            'is_eligible' => 'boolean',

            'is_provisional' => 'boolean',

            'factors' => 'array',

            'metadata' => 'array',

            'calculated_at' => 'datetime',
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
