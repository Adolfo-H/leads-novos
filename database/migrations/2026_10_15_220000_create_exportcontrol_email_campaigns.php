<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 180);
            $table->string('subject', 180);
            $table->text('body');
            $table->string('status', 20)->default('draft')->index();
            $table->string('from_address', 255);
            $table->string('from_name', 150);
            $table->string('reply_to', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('test_sent_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('email_campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->string('source_key', 160)->nullable();
            $table->string('email', 255);
            $table->string('normalized_email', 255);
            $table->string('contact_name', 180)->nullable();
            $table->string('company_name', 255)->nullable();
            $table->string('job_title', 180)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('reserved_at')->nullable();
            $table->timestampTz('attempted_at')->nullable()->index();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('replied_at')->nullable();
            $table->string('message_id', 255)->nullable()->unique();
            $table->string('error_message', 255)->nullable();
            $table->timestampsTz();
            $table->unique(['email_campaign_id', 'normalized_email'], 'campaign_email_unique');
            $table->index(['email_campaign_id', 'status']);
        });

        Schema::create('email_campaign_suppressions', function (Blueprint $table): void {
            $table->id();
            $table->string('normalized_email', 255)->unique();
            $table->string('reason', 24)->default('unsubscribe');
            $table->timestampsTz();
        });

        Schema::create('email_campaign_inbox_cursors', function (Blueprint $table): void {
            $table->id();
            $table->string('mailbox', 255)->unique();
            $table->string('uid_validity', 40)->nullable();
            $table->unsignedBigInteger('last_uid')->default(0);
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('email_campaign_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->foreignId('email_campaign_recipient_id')->constrained('email_campaign_recipients')->cascadeOnDelete();
            $table->string('mailbox', 255);
            $table->string('uid_validity', 40);
            $table->unsignedBigInteger('imap_uid');
            $table->string('from_email', 255);
            $table->string('subject', 255)->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();
            $table->unique(['mailbox', 'uid_validity', 'imap_uid'], 'campaign_inbox_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_replies');
        Schema::dropIfExists('email_campaign_inbox_cursors');
        Schema::dropIfExists('email_campaign_suppressions');
        Schema::dropIfExists('email_campaign_recipients');
        Schema::dropIfExists('email_campaigns');
    }
};
