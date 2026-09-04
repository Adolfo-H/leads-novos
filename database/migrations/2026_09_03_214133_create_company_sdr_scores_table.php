<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'company_sdr_scores',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId('company_id')
                    ->unique()
                    ->constrained()
                    ->cascadeOnDelete();

                $table
                    ->unsignedSmallInteger('score')
                    ->default(0);

                $table
                    ->string('priority', 30)
                    ->default('low');

                $table
                    ->string('label', 100);

                $table
                    ->boolean('is_eligible')
                    ->default(true);

                $table
                    ->boolean('is_provisional')
                    ->default(true);

                $table
                    ->string('blocked_reason', 100)
                    ->nullable();

                $table
                    ->jsonb('factors')
                    ->default('[]');

                $table
                    ->string('version', 30)
                    ->default('v1');

                $table
                    ->jsonb('metadata')
                    ->default('{}');

                $table
                    ->timestampTz('calculated_at')
                    ->nullable();

                $table->timestamps();

                $table->index('score');
                $table->index('priority');
                $table->index('is_eligible');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'company_sdr_scores'
        );
    }
};
