<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyHubSpotLead extends Model
{
    protected $table =
        'company_hubspot_leads';

    protected $fillable = [
        'company_id',
        'hubspot_company_id',
        'hubspot_contact_id',
        'hubspot_deal_id',
        'pipeline_id',
        'deal_stage_id',
        'work_status',
        'last_activity_type',
        'last_activity_at',
        'open_task_count',
        'last_task_due_at',
        'synced_at',
        'status_synced_at',
        'sync_error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',

            'last_task_due_at' => 'datetime',

            'synced_at' => 'datetime',

            'status_synced_at' => 'datetime',

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
