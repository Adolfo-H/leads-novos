<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HubSpotPipelineStage extends Model
{
    protected $table =
        'hubspot_pipeline_stages';

    protected $fillable = [
        'pipeline_id',
        'pipeline_label',
        'stage_id',
        'stage_label',
        'display_order',
        'is_closed',
        'is_closed_won',
        'raw_properties',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',

            'is_closed' => 'boolean',

            'is_closed_won' => 'boolean',

            'raw_properties' => 'array',
        ];
    }

    /**
     * @return HasMany<HubSpotDeal, $this>
     */
    public function deals(): HasMany
    {
        return $this->hasMany(
            HubSpotDeal::class,
            'hubspot_pipeline_stage_id'
        );
    }
}
