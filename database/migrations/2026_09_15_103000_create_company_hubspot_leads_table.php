<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'company_hubspot_leads',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId('company_id')
                    ->unique()
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->string('hubspot_company_id')
                    ->nullable();

                $table
                    ->string('hubspot_contact_id')
                    ->nullable();

                $table
                    ->string('hubspot_deal_id')
                    ->nullable();

                $table
                    ->string('pipeline_id')
                    ->nullable();

                $table
                    ->string('deal_stage_id')
                    ->nullable();

                $table
                    ->string(
                        'last_activity_type',
                        50
                    )
                    ->nullable();

                $table
                    ->timestamp(
                        'last_activity_at'
                    )
                    ->nullable();

                $table
                    ->unsignedInteger(
                        'open_task_count'
                    )
                    ->default(0);

                $table
                    ->timestamp(
                        'last_task_due_at'
                    )
                    ->nullable();

                $table
                    ->timestamp('synced_at')
                    ->nullable();

                $table
                    ->text('sync_error')
                    ->nullable();

                $table
                    ->json('metadata')
                    ->nullable();

                $table->timestamps();

                $table->index(
                    'hubspot_company_id'
                );

                $table->index(
                    'hubspot_deal_id'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'company_hubspot_leads'
        );
    }
};
