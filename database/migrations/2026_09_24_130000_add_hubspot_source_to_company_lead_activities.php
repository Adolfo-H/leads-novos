<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'company_lead_activities',
            function (
                Blueprint $table
            ): void {
                $table
                    ->string(
                        'source',
                        30
                    )
                    ->nullable()
                    ->after(
                        'type'
                    )
                    ->index();

                $table
                    ->string(
                        'source_object_type',
                        40
                    )
                    ->nullable()
                    ->after(
                        'source'
                    );

                $table
                    ->string(
                        'source_object_id',
                        100
                    )
                    ->nullable()
                    ->after(
                        'source_object_type'
                    );

                $table
                    ->timestamp(
                        'source_updated_at'
                    )
                    ->nullable()
                    ->after(
                        'occurred_at'
                    );

                $table
                    ->boolean(
                        'is_deleted'
                    )
                    ->default(false)
                    ->after(
                        'source_updated_at'
                    )
                    ->index();

                $table->unique(
                    [
                        'company_id',
                        'source',
                        'source_object_type',
                        'source_object_id',
                    ],
                    'cla_hubspot_object_unique'
                );

                $table->index(
                    [
                        'source',
                        'source_object_type',
                        'source_object_id',
                    ],
                    'cla_hubspot_object_lookup'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'company_lead_activities',
            function (
                Blueprint $table
            ): void {
                $table->dropUnique(
                    'cla_hubspot_object_unique'
                );

                $table->dropIndex(
                    'cla_hubspot_object_lookup'
                );

                $table->dropColumn([
                    'source',
                    'source_object_type',
                    'source_object_id',
                    'source_updated_at',
                    'is_deleted',
                ]);
            }
        );
    }
};
