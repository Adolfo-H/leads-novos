<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HubSpotWebhookEvent extends Model
{
    protected $table =
        'hubspot_webhook_events';

    protected $fillable = [
        'event_key',
        'portal_id',
        'app_id',
        'subscription_id',
        'subscription_type',
        'object_type',
        'object_type_id',
        'object_id',
        'property_name',
        'occurred_at',
        'status',
        'attempts',
        'payload',
        'error',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',

            'payload' => 'array',

            'occurred_at' => 'datetime',

            'processed_at' => 'datetime',
        ];
    }
}
