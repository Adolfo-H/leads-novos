<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HubSpotContact extends Model
{
    protected $table =
        'hubspot_contacts';

    protected $fillable = [
        'hubspot_import_run_id',
        'hubspot_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'mobile_phone',
        'job_title',
        'company_name',
        'owner_name',
        'lifecycle_stage',
        'last_activity_at',
        'hubspot_created_at',
        'raw_properties',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',

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
            'hubspot_company_contact',
            'hubspot_contact_id',
            'hubspot_company_id',
        )
            ->withPivot(
                'is_primary'
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
            'hubspot_deal_contact',
            'hubspot_contact_id',
            'hubspot_deal_id',
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
            'hubspot_contact_task',
            'hubspot_contact_id',
            'hubspot_task_id',
        )
            ->withTimestamps();
    }
}
