<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_segments', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->jsonb('filters');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('email_campaign_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->foreignId('draft_campaign_id')->nullable()->constrained('email_campaigns')->nullOnDelete();
            $table->string('name', 150);
            $table->string('subject', 180);
            $table->text('body');
            $table->unsignedSmallInteger('delay_days');
            $table->string('status', 24)->default('planned')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('email_campaign_sequence_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sequence_id')->constrained('email_campaign_sequences')->cascadeOnDelete();
            $table->foreignId('original_recipient_id')->constrained('email_campaign_recipients')->cascadeOnDelete();
            $table->foreignId('followup_recipient_id')->unique()->constrained('email_campaign_recipients')->cascadeOnDelete();
            $table->timestampsTz();
        });
        Schema::create('email_campaign_outbound_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_campaign_reply_id')->constrained('email_campaign_replies')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_email', 255);
            $table->text('body');
            $table->string('status', 24)->default('sending');
            $table->string('request_uuid', 36)->unique();
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->string('error_class', 120)->nullable();
            $table->timestampsTz();
        });
        Schema::table('email_campaign_replies', function (Blueprint $table): void {
            $table->string('incoming_message_id', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_replies', function (Blueprint $table): void {
            $table->dropColumn('incoming_message_id');
        });
        Schema::dropIfExists('email_campaign_outbound_replies');
        Schema::dropIfExists('email_campaign_sequence_links');
        Schema::dropIfExists('email_campaign_sequences');
        Schema::dropIfExists('email_campaign_segments');
    }
};
