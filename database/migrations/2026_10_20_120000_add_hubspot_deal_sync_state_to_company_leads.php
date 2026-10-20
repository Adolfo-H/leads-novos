<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'company_hubspot_leads',
            function (Blueprint $table): void {
                $table
                    ->string('hubspot_deal_sync_status', 40)
                    ->default('not_started')
                    ->index();

                $table
                    ->uuid('hubspot_deal_operation_id')
                    ->nullable()
                    ->unique();

                $table
                    ->timestamp('hubspot_deal_sync_started_at')
                    ->nullable();

                $table
                    ->timestamp('hubspot_deal_sync_completed_at')
                    ->nullable();

                $table
                    ->string('hubspot_deal_sync_error_category', 40)
                    ->nullable();
            }
        );

        DB::table('company_hubspot_leads')
            ->whereNotNull('hubspot_deal_id')
            ->update([
                'hubspot_deal_sync_status' => 'completed',
                'hubspot_deal_sync_completed_at' => DB::raw(
                    'COALESCE(synced_at, updated_at)'
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table(
            'company_hubspot_leads',
            function (Blueprint $table): void {
                $table->dropUnique(
                    'company_hubspot_leads_hubspot_deal_operation_id_unique'
                );
                $table->dropIndex(
                    'company_hubspot_leads_hubspot_deal_sync_status_index'
                );
                $table->dropColumn([
                    'hubspot_deal_sync_status',
                    'hubspot_deal_operation_id',
                    'hubspot_deal_sync_started_at',
                    'hubspot_deal_sync_completed_at',
                    'hubspot_deal_sync_error_category',
                ]);
            }
        );
    }
};
