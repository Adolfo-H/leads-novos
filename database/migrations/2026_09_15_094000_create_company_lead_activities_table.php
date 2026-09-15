<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'company_lead_activities',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId('company_id')
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->string(
                    'type',
                    50
                );

                $table->string(
                    'title',
                    150
                );

                $table
                    ->text('description')
                    ->nullable();

                $table
                    ->json('metadata')
                    ->nullable();

                $table
                    ->timestamp('occurred_at');

                $table->timestamps();

                $table->index([
                    'company_id',
                    'occurred_at',
                ]);

                $table->index('type');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'company_lead_activities'
        );
    }
};
