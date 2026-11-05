<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_public_external_company_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hubspot_company_id')->constrained('hubspot_companies')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('queued');
            $table->string('error_code', 40)->nullable();
            $table->unsignedSmallInteger('findings_count')->default(0);
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['hubspot_company_id', 'created_at'], 'wa14_external_run_recent');
        });
        Schema::create('whatsapp_public_external_company_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hubspot_company_id')->constrained('hubspot_companies')->cascadeOnDelete();
            $table->string('provider', 22); // brave_index | google_places
            $table->string('phone_e164', 16);
            $table->string('finding_type', 24); // explicit_whatsapp_link | public_phone_not_verified
            $table->string('source_url', 750);
            $table->string('source_hash', 64);
            $table->string('title', 180)->nullable();
            $table->string('review_status', 20)->default('pending'); // pending | accepted | discarded
            $table->timestampTz('discovered_at');
            $table->timestampsTz();
            $table->unique(['hubspot_company_id', 'source_hash'], 'wa14_external_source_unique');
            $table->index(['hubspot_company_id', 'review_status'], 'wa14_external_company_review');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_public_external_company_findings');
        Schema::dropIfExists('whatsapp_public_external_company_runs');
    }
};
