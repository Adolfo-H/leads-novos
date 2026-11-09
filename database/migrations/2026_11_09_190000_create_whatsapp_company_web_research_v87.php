<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_company_web_runs_v87', function (Blueprint $table): void {
            $table->id();
            $table->string('target_key', 85)->index();
            $table->string('company_label', 180);
            $table->string('domain', 255)->nullable();
            $table->string('status', 24)->default('queued')->index();
            $table->string('error_code', 50)->nullable();
            $table->unsignedInteger('findings_count')->default(0);
            $table->jsonb('source_status')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['target_key', 'id'], 'wa87_run_target_latest');
        });

        Schema::create('whatsapp_company_web_findings_v87', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('whatsapp_company_web_runs_v87')->cascadeOnDelete();
            $table->string('phone_e164', 16);
            $table->string('kind', 30); // explicit_whatsapp_link | public_phone_not_verified
            $table->string('provider', 25);
            $table->string('source_url', 750);
            $table->string('source_hash', 64);
            $table->string('title', 180)->nullable();
            $table->timestampTz('discovered_at');
            $table->timestampsTz();
            $table->unique(['run_id', 'source_hash'], 'wa87_run_source_unique');
            $table->index(['run_id', 'phone_e164'], 'wa87_run_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_company_web_findings_v87');
        Schema::dropIfExists('whatsapp_company_web_runs_v87');
    }
};
