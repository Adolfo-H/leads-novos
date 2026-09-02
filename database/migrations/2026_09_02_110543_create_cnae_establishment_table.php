<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cnae_establishment', function (Blueprint $table) {
            $table->id();

            $table->foreignId('establishment_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('cnae_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->boolean('is_primary')->default(false)->index();

            $table->timestampsTz();

            $table->unique([
                'establishment_id',
                'cnae_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cnae_establishment');
    }
};
