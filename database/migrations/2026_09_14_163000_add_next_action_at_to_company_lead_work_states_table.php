<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'company_lead_work_states',
            function (Blueprint $table): void {
                $table
                    ->timestamp('next_action_at')
                    ->nullable()
                    ->after('last_action_at');

                $table->index(
                    'next_action_at'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'company_lead_work_states',
            function (Blueprint $table): void {
                $table->dropIndex([
                    'next_action_at',
                ]);

                $table->dropColumn(
                    'next_action_at'
                );
            }
        );
    }
};
