<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_web_scan_batches', function (Blueprint $table): void {
            $table->text('target_phone_ids')->nullable();
        });
        Schema::create('whatsapp_manual_numbers_v16', function (Blueprint $table): void {
            $table->id();
            $table->string('e164', 16)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('whatsapp_phone_format_reviews_v16', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('original_phone_id')->unique()->constrained('whatsapp_phone_numbers')->cascadeOnDelete();
            $table->string('decision', 16); // corrected | invalid
            $table->string('corrected_e164', 16)->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('whatsapp_company_channel_links_v16', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->unique()->constrained('whatsapp_public_company_channels')->cascadeOnDelete();
            $table->string('target_type', 12); // local | hubspot
            $table->unsignedBigInteger('target_id');
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('linked_at');
            $table->timestampsTz();
            $table->index(['target_type', 'target_id'], 'wa16_company_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_company_channel_links_v16');
        Schema::dropIfExists('whatsapp_phone_format_reviews_v16');
        Schema::dropIfExists('whatsapp_manual_numbers_v16');
        Schema::table('whatsapp_web_scan_batches', fn (Blueprint $table) => $table->dropColumn('target_phone_ids'));
    }
};
