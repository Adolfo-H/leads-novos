<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubSpotRefreshItem extends Model
{
    protected $table =
        'hubspot_refresh_items';

    protected $fillable = [
        'run_id',
        'company_id',
        'status',
        'changes',
        'error',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<HubSpotRefreshRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(
            HubSpotRefreshRun::class,
            'run_id'
        );
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
