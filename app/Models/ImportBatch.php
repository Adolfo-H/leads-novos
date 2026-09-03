<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'uuid',
        'user_id',
        'source_type',
        'original_filename',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'duplicate_rows',
        'existing_rows',
        'processed_rows',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ImportItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ImportItem::class);
    }
}
