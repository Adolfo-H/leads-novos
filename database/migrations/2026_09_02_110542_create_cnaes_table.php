<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cnaes', function (Blueprint $table) {
            $table->id();

            // Exemplo: 4622200
            $table->string('code', 7)->unique();

            $table->string('description', 255)->nullable();

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cnaes');
    }
};
