<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HubSpotCompany extends Model
{
    protected $table =
        'hubspot_companies';

    protected $fillable = [
        'hubspot_import_run_id',
        'company_id',
        'matched_cnpj_root',
        'matched_company_name',
        'match_source',
        'hubspot_id',
        'name',
        'domain',
        'lifecycle_stage',
        'lead_status',
        'owner_name',
        'city',
        'state',
        'phone',
        'last_activity_at',
        'hubspot_created_at',
        'hubspot_updated_at',
        'raw_properties',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',

            'hubspot_created_at' => 'datetime',

            'hubspot_updated_at' => 'datetime',

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
     * Empresa identificada na base Receita /
     * Prospector.
     *
     * @return BelongsTo<Company, $this>
     */
    public function prospectorCompany(): BelongsTo
    {
        return $this->belongsTo(
            Company::class,
            'company_id'
        );
    }

    /**
     * @return BelongsToMany<HubSpotDeal, $this>
     */
    public function deals(): BelongsToMany
    {
        return $this->belongsToMany(
            HubSpotDeal::class,
            'hubspot_company_deal',
            'hubspot_company_id',
            'hubspot_deal_id',
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
            'hubspot_company_contact',
            'hubspot_company_id',
            'hubspot_contact_id',
        )
            ->withPivot(
                'is_primary'
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
            'hubspot_company_task',
            'hubspot_company_id',
            'hubspot_task_id',
        )
            ->withTimestamps();
    }
}
