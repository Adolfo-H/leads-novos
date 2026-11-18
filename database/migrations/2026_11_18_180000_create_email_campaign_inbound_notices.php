<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_inbound_notices', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->foreignId('email_campaign_recipient_id')->constrained('email_campaign_recipients')->cascadeOnDelete();
            $table->string('mailbox', 255);
            $table->string('uid_validity', 40);
            $table->unsignedBigInteger('imap_uid');
            $table->string('from_email', 255);
            $table->string('subject', 255)->nullable();
            $table->string('notice_type', 32);
            $table->string('link_method', 32);
            $table->boolean('requires_review')->default(false);
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();
            $table->unique(['mailbox', 'uid_validity', 'imap_uid'], 'ec_inbound_notice_uid_unique');
            $table->index(['email_campaign_id', 'received_at'], 'ec_inbound_notice_campaign_recent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_inbound_notices');
    }
};
