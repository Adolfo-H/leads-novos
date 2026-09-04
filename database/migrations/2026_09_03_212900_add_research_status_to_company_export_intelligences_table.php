<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'company_export_intelligences',
            function (Blueprint $table): void {
                $table
                    ->string(
                        'research_status',
                        32
                    )
                    ->default('idle');

                $table
                    ->string(
                        'research_provider',
                        100
                    )
                    ->nullable();

                $table
                    ->text(
                        'research_error'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'research_started_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'research_completed_at'
                    )
                    ->nullable();

                $table->index(
                    'research_status'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'company_export_intelligences',
            function (Blueprint $table): void {
                $table->dropIndex([
                    'research_status',
                ]);

                $table->dropColumn([
                    'research_status',
                    'research_provider',
                    'research_error',
                    'research_started_at',
                    'research_completed_at',
                ]);
            }
        );
    }
};
