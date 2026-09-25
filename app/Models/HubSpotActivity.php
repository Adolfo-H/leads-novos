<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HubSpotActivity extends Model
{
    protected $table =
        'hubspot_activities';

    protected $fillable = [
        'hubspot_id',
        'object_type',
        'title',
        'description',
        'occurred_at',
        'source_updated_at',
        'is_deleted',
        'raw_properties',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',

            'source_updated_at' => 'datetime',

            'is_deleted' => 'boolean',

            'raw_properties' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<HubSpotCompany, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(
            HubSpotCompany::class,
            'hubspot_activity_company',
            'hubspot_activity_id',
            'hubspot_company_id',
        )
            ->withTimestamps();
    }
}
