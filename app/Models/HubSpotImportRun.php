<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HubSpotImportRun extends Model
{
    protected $table =
        'hubspot_import_runs';

    protected $fillable = [
        'uuid',
        'source',
        'status',
        'source_files',
        'stats',
        'started_at',
        'finished_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'source_files' => 'array',

            'stats' => 'array',

            'started_at' => 'datetime',

            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<HubSpotCompany, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(
            HubSpotCompany::class,
            'hubspot_import_run_id'
        );
    }

    /**
     * @return HasMany<HubSpotDeal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(
            HubSpotDeal::class,
            'hubspot_import_run_id'
        );
    }

    /**
     * @return HasMany<HubSpotContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(
            HubSpotContact::class,
            'hubspot_import_run_id'
        );
    }

    /**
     * @return HasMany<HubSpotTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(
            HubSpotTask::class,
            'hubspot_import_run_id'
        );
    }
}
