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
            function (
                Blueprint $table
            ): void {
                $table
                    ->string(
                        'commercial_status',
                        32
                    )
                    ->default(
                        'new'
                    )
                    ->after(
                        'work_status'
                    )
                    ->index();
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'company_hubspot_leads',
            function (
                Blueprint $table
            ): void {
                $table
                    ->dropColumn(
                        'commercial_status'
                    );
            }
        );
    }
};
