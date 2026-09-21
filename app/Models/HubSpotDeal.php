<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HubSpotDeal extends Model
{
    protected $table =
        'hubspot_deals';

    protected $fillable = [
        'hubspot_import_run_id',
        'hubspot_pipeline_stage_id',
        'hubspot_id',
        'name',
        'pipeline_id',
        'pipeline_label',
        'stage_id',
        'stage_label',
        'owner_name',
        'amount',
        'currency',
        'is_closed',
        'is_closed_won',
        'hubspot_created_at',
        'closed_at',
        'last_activity_at',
        'raw_properties',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',

            'is_closed' => 'boolean',

            'is_closed_won' => 'boolean',

            'hubspot_created_at' => 'datetime',

            'closed_at' => 'datetime',

            'last_activity_at' => 'datetime',

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
     * @return BelongsTo<HubSpotPipelineStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(
            HubSpotPipelineStage::class,
            'hubspot_pipeline_stage_id'
        );
    }

    /**
     * @return BelongsToMany<HubSpotCompany, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            HubSpotCompany::class,
            'hubspot_company_deal',
            'hubspot_deal_id',
            'hubspot_company_id',
        )
            ->withPivot(
                'is_primary'
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
            'hubspot_deal_contact',
            'hubspot_deal_id',
            'hubspot_contact_id',
        )
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<HubSpotTask, $this>
     */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(
            HubSpotTask::class,
            'hubspot_deal_task',
            'hubspot_deal_id',
            'hubspot_task_id',
        )
            ->withTimestamps();
    }
}
