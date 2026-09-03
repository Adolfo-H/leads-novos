<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportItem extends Model
{
    protected $fillable = [
        'import_batch_id',
        'row_number',
        'raw_cnpj',
        'normalized_cnpj',
        'status',
        'company_id',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(
            ImportBatch::class,
            'import_batch_id'
        );
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }
}
