<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'company_lead_work_states',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId('company_id')
                    ->unique()
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->foreignId('assigned_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table
                    ->string('status', 30)
                    ->default('new');

                $table
                    ->text('note')
                    ->nullable();

                $table
                    ->timestamp('last_action_at')
                    ->nullable();

                $table->timestamps();

                $table->index('status');
                $table->index('last_action_at');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'company_lead_work_states'
        );
    }
};
