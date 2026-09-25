<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Espelho das atividades comerciais do
         * HubSpot.
         *
         * Diferente de company_lead_activities,
         * este registro NÃO exige uma Company
         * fiscal previamente identificada.
         */
        Schema::create(
            'hubspot_activities',
            function (
                Blueprint $table
            ): void {
                $table->id();

                $table
                    ->string(
                        'hubspot_id',
                        100
                    );

                $table
                    ->string(
                        'object_type',
                        40
                    )
                    ->index();

                $table
                    ->string(
                        'title',
                        180
                    )
                    ->nullable();

                $table
                    ->text(
                        'description'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'occurred_at'
                    )
                    ->nullable()
                    ->index();

                $table
                    ->timestampTz(
                        'source_updated_at'
                    )
                    ->nullable();

                $table
                    ->boolean(
                        'is_deleted'
                    )
                    ->default(false)
                    ->index();

                $table
                    ->jsonb(
                        'raw_properties'
                    )
                    ->nullable();

                $table->timestampsTz();

                $table->unique(
                    [
                        'object_type',
                        'hubspot_id',
                    ],
                    'hubspot_activity_object_unique'
                );
            }
        );

        /*
         * Uma atividade pode estar associada
         * a mais de uma Company do HubSpot.
         */
        Schema::create(
            'hubspot_activity_company',
            function (
                Blueprint $table
            ): void {
                $table->id();

                $table
                    ->foreignId(
                        'hubspot_activity_id'
                    )
                    ->constrained(
                        'hubspot_activities'
                    )
                    ->cascadeOnDelete();

                $table
                    ->foreignId(
                        'hubspot_company_id'
                    )
                    ->constrained(
                        'hubspot_companies'
                    )
                    ->cascadeOnDelete();

                $table->timestampsTz();

                $table->unique(
                    [
                        'hubspot_activity_id',
                        'hubspot_company_id',
                    ],
                    'hubspot_activity_company_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'hubspot_activity_company'
        );

        Schema::dropIfExists(
            'hubspot_activities'
        );
    }
};
