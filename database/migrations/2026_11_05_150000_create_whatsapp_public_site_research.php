<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_public_site_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedSmallInteger('requested_sites');
            $table->unsignedSmallInteger('processed_sites')->default(0);
            $table->unsignedSmallInteger('found_links')->default(0);
            $table->unsignedSmallInteger('matched_numbers')->default(0);
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('whatsapp_public_site_checks', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 253)->unique();
            $table->string('status', 20)->default('queued');
            $table->foreignId('run_id')->nullable()->constrained('whatsapp_public_site_runs')->nullOnDelete();
            $table->unsignedTinyInteger('pages_checked')->default(0);
            $table->unsignedSmallInteger('links_found')->default(0);
            $table->string('error_code', 35)->nullable();
            $table->timestampTz('checked_at')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('whatsapp_public_site_evidences', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 253)->index();
            $table->string('e164', 16);
            $table->string('source_url', 420);
            $table->string('wa_url', 60);
            $table->string('link_label', 100)->nullable();
            $table->string('company_label', 180)->nullable();
            $table->foreignId('phone_id')->nullable()->constrained('whatsapp_phone_numbers')->nullOnDelete();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at')->index();
            $table->timestampsTz();
            $table->unique(['domain', 'e164'], 'wa_public_domain_phone_unique');
            $table->index(['phone_id', 'last_seen_at'], 'wa_public_phone_recent');
        });

        Schema::create('whatsapp_public_site_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('auto_enabled')->default(false);
            $table->unsignedTinyInteger('sites_per_day')->default(5);
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('last_queued_on')->nullable();
            $table->timestampsTz();
        });
        DB::table('whatsapp_public_site_settings')->insert([
            'id' => 1, 'auto_enabled' => false, 'sites_per_day' => 5,
            'created_at' => now('UTC'), 'updated_at' => now('UTC'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_public_site_settings');
        Schema::dropIfExists('whatsapp_public_site_evidences');
        Schema::dropIfExists('whatsapp_public_site_checks');
        Schema::dropIfExists('whatsapp_public_site_runs');
    }
};
