<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_phone_numbers', function (Blueprint $table): void {
            $table->id();
            $table->string('phone_key', 70)->unique();
            $table->string('digits', 80);
            $table->string('e164', 16)->nullable()->index();
            $table->string('kind', 16)->index(); // mobile | landline | review
            $table->string('status', 12)->default('unknown')->index();
            $table->string('evidence', 48)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->unsignedInteger('contacts_count')->default(0)->index();
            $table->unsignedInteger('occurrences_count')->default(0);
            $table->timestampTz('last_scanned_at')->nullable();
            $table->timestampsTz();
            $table->index(['kind', 'status', 'contacts_count'], 'wa_num_queue_index');
        });

        Schema::create('whatsapp_phone_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('phone_id')->constrained('whatsapp_phone_numbers')->cascadeOnDelete();
            $table->string('source_key', 160);
            $table->string('origin', 16); // hubspot | manual | receita
            $table->string('phone_field', 20);
            $table->string('raw_digits', 80);
            $table->string('contact_label', 180);
            $table->string('company_label', 180)->nullable();
            $table->uuid('scan_id');
            $table->timestampsTz();
            $table->unique(['source_key', 'phone_field'], 'wa_sources_identity_unique');
            $table->index(['phone_id', 'origin'], 'wa_sources_phone_origin');
            $table->index('scan_id');
        });

        Schema::create('whatsapp_phone_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('phone_id')->constrained('whatsapp_phone_numbers')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('old_status', 12);
            $table->string('new_status', 12);
            $table->string('evidence', 48);
            $table->string('note', 300)->nullable();
            $table->timestampTz('created_at');
            $table->index(['phone_id', 'created_at']);
        });

        Schema::create('whatsapp_phone_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('numbers_count');
            $table->unsignedInteger('references_count');
            $table->timestampTz('finished_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_phone_sync_runs');
        Schema::dropIfExists('whatsapp_phone_events');
        Schema::dropIfExists('whatsapp_phone_sources');
        Schema::dropIfExists('whatsapp_phone_numbers');
    }
};
