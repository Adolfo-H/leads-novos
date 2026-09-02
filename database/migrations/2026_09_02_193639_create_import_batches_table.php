<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')
                ->unique();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('source_type', 30)
                ->default('manual');

            $table->string('original_filename')
                ->nullable();

            $table->string('status', 30)
                ->default('pending')
                ->index();

            $table->unsignedInteger('total_rows')
                ->default(0);

            $table->unsignedInteger('valid_rows')
                ->default(0);

            $table->unsignedInteger('invalid_rows')
                ->default(0);

            $table->unsignedInteger('duplicate_rows')
                ->default(0);

            $table->unsignedInteger('existing_rows')
                ->default(0);

            $table->unsignedInteger('processed_rows')
                ->default(0);

            $table->text('error_message')
                ->nullable();

            $table->jsonb('metadata')
                ->nullable();

            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
