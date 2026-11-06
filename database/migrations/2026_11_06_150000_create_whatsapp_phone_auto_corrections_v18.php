<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_phone_auto_corrections_v18', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('original_phone_id')->unique()
                ->constrained('whatsapp_phone_numbers')->cascadeOnDelete();
            $table->string('original_phone_key', 70)->unique();
            $table->string('original_digits', 80);
            $table->string('corrected_e164', 16)->index();
            $table->string('rule', 48);
            $table->timestampTz('applied_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_phone_auto_corrections_v18');
    }
};
