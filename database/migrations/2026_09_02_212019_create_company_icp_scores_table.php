<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_icp_scores', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('company_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table
                ->unsignedSmallInteger('score');

            $table
                ->string('grade', 1);

            $table
                ->string('label', 80);

            $table
                ->string('version', 30)
                ->default('v1');

            $table
                ->jsonb('factors');

            $table
                ->timestampTz('calculated_at');

            $table->timestampsTz();

            $table->index([
                'grade',
                'score',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'company_icp_scores'
        );
    }
};
