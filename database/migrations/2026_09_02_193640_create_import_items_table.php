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
        Schema::create('import_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('import_batch_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedInteger('row_number');

            $table->string('raw_cnpj', 50);

            $table->string('normalized_cnpj', 14)
                ->nullable()
                ->index();

            $table->string('status', 30)
                ->default('pending')
                ->index();

            $table->foreignId('company_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->text('error_message')
                ->nullable();

            $table->jsonb('metadata')
                ->nullable();

            $table->timestampsTz();

            $table->unique([
                'import_batch_id',
                'row_number',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_items');
    }
};
