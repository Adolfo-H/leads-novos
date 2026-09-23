<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HubSpotRefreshRun extends Model
{
    protected $table =
        'hubspot_refresh_runs';

    protected $fillable = [
        'initiated_by',
        'scope',
        'status',
        'total',
        'processed',
        'changed',
        'unchanged',
        'failed',
        'summary',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'processed' => 'integer',
            'changed' => 'integer',
            'unchanged' => 'integer',
            'failed' => 'integer',
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'initiated_by'
        );
    }

    /**
     * @return HasMany<HubSpotRefreshItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(
            HubSpotRefreshItem::class,
            'run_id'
        );
    }
}
