<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'hubspot_webhook_events',
            function (
                Blueprint $table
            ): void {
                $table->id();

                $table
                    ->string(
                        'event_key',
                        64
                    )
                    ->unique();

                $table
                    ->string(
                        'portal_id',
                        50
                    )
                    ->nullable();

                $table
                    ->string(
                        'app_id',
                        50
                    )
                    ->nullable();

                $table
                    ->string(
                        'subscription_id',
                        50
                    )
                    ->nullable();

                $table
                    ->string(
                        'subscription_type',
                        100
                    );

                $table
                    ->string(
                        'object_type',
                        40
                    );

                $table
                    ->string(
                        'object_type_id',
                        60
                    )
                    ->nullable();

                $table
                    ->string(
                        'object_id',
                        100
                    );

                $table
                    ->string(
                        'property_name',
                        191
                    )
                    ->nullable();

                $table
                    ->timestamp(
                        'occurred_at'
                    )
                    ->nullable();

                $table
                    ->string(
                        'status',
                        30
                    )
                    ->default(
                        'received'
                    )
                    ->index();

                $table
                    ->unsignedInteger(
                        'attempts'
                    )
                    ->default(0);

                $table
                    ->json(
                        'payload'
                    );

                $table
                    ->text(
                        'error'
                    )
                    ->nullable();

                $table
                    ->timestamp(
                        'processed_at'
                    )
                    ->nullable();

                $table->timestamps();

                $table->index([
                    'object_type',
                    'object_id',
                ]);

                $table->index([
                    'portal_id',
                    'occurred_at',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'hubspot_webhook_events'
        );
    }
};
