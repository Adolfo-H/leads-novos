<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyExportIntelligence extends Model
{
    protected $fillable = [
        'company_id',

        'direct_status',
        'direct_confidence',
        'direct_confirmed',
        'direct_summary',

        'indirect_status',
        'indirect_confidence',
        'indirect_confirmed',
        'indirect_summary',

        'trading_status',
        'trading_confidence',
        'trading_confirmed',
        'trading_summary',

        'overall_summary',

        'research_status',
        'research_provider',
        'research_error',
        'research_started_at',
        'research_completed_at',

        'researched_at',
        'reviewed_at',

        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'direct_confidence' => 'integer',

            'direct_confirmed' => 'boolean',

            'indirect_confidence' => 'integer',

            'indirect_confirmed' => 'boolean',

            'trading_confidence' => 'integer',

            'trading_confirmed' => 'boolean',

            'researched_at' => 'datetime',

            'reviewed_at' => 'datetime',

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
