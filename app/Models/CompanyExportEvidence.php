<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyExportEvidence extends Model
{
    protected $table =
        'company_export_evidence';

    protected $fillable = [
        'company_id',
        'fingerprint',
        'dimension',
        'signal',

        'source_type',
        'source_name',
        'source_url',

        'title',
        'evidence_text',

        'confidence',
        'is_confirmed',

        'observed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',

            'is_confirmed' => 'boolean',

            'observed_at' => 'datetime',

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
