<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Observações experimentais separadas de confirmações humanas.
        Schema::create('whatsapp_web_probe_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('phone_id')->unique()->constrained('whatsapp_phone_numbers')->cascadeOnDelete();
            $table->string('result', 20)->index(); // found | not_found | inconclusive | error
            $table->string('provider', 32)->default('baileys_unofficial');
            $table->timestampTz('checked_at')->index();
            $table->unsignedInteger('attempts')->default(1);
            $table->timestampsTz();
        });

        Schema::create('whatsapp_web_probe_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('phone_id')->constrained('whatsapp_phone_numbers')->cascadeOnDelete();
            $table->string('result', 20);
            $table->string('provider', 32)->default('baileys_unofficial');
            $table->timestampTz('checked_at');
            $table->index(['phone_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_web_probe_events');
        Schema::dropIfExists('whatsapp_web_probe_results');
    }
};
