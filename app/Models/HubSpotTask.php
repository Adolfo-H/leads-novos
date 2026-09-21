<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HubSpotTask extends Model
{
    protected $table =
        'hubspot_tasks';

    protected $fillable = [
        'hubspot_import_run_id',
        'hubspot_id',
        'title',
        'status',
        'stage',
        'type',
        'assigned_to',
        'is_open',
        'is_overdue',
        'due_at',
        'completed_at',
        'hubspot_created_at',
        'notes',
        'raw_properties',
    ];

    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',

            'is_overdue' => 'boolean',

            'due_at' => 'datetime',

            'completed_at' => 'datetime',

            'hubspot_created_at' => 'datetime',

            'raw_properties' => 'array',
        ];
    }

    /**
     * @return BelongsTo<HubSpotImportRun, $this>
     */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(
            HubSpotImportRun::class,
            'hubspot_import_run_id'
        );
    }

    /**
     * @return BelongsToMany<HubSpotCompany, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            HubSpotCompany::class,
            'hubspot_company_task',
            'hubspot_task_id',
            'hubspot_company_id',
        )
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<HubSpotDeal, $this>
     */
    public function deals(): BelongsToMany
    {
        return $this->belongsToMany(
            HubSpotDeal::class,
            'hubspot_deal_task',
            'hubspot_task_id',
            'hubspot_deal_id',
        )
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<HubSpotContact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            HubSpotContact::class,
            'hubspot_contact_task',
            'hubspot_task_id',
            'hubspot_contact_id',
        )
            ->withTimestamps();
    }
}
