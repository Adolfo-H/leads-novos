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
                        'hubspot_task_id',
                        100
                    )
                    ->nullable()
                    ->after(
                        'hubspot_deal_id'
                    );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'company_hubspot_leads',
            function (Blueprint $table): void {
                $table->dropColumn(
                    'hubspot_task_id'
                );
            }
        );
    }
};
