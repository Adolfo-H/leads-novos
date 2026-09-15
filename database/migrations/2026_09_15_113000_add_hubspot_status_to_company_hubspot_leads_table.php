<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'company_hubspot_leads',
            function (Blueprint $table): void {
                $table
                    ->string(
                        'work_status',
                        30
                    )
                    ->default('new')
                    ->after('deal_stage_id');

                $table
                    ->timestamp(
                        'status_synced_at'
                    )
                    ->nullable()
                    ->after('synced_at');

                $table->index(
                    'work_status'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'company_hubspot_leads',
            function (Blueprint $table): void {
                $table->dropIndex([
                    'work_status',
                ]);

                $table->dropColumn([
                    'work_status',
                    'status_synced_at',
                ]);
            }
        );
    }
};
