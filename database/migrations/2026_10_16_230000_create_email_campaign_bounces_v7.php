<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_bounces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->foreignId('email_campaign_recipient_id')->nullable()->constrained('email_campaign_recipients')->cascadeOnDelete();
            $table->foreignId('email_campaign_test_message_id')->nullable()->constrained('email_campaign_test_messages')->cascadeOnDelete();
            $table->string('mailbox', 255);
            $table->string('uid_validity', 40);
            $table->unsignedBigInteger('imap_uid');
            $table->string('final_recipient', 255);
            $table->string('status_code', 16);
            $table->string('action', 16);
            $table->string('classification', 32)->index();
            $table->string('diagnostic', 200)->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();
            $table->unique(['mailbox', 'uid_validity', 'imap_uid'], 'campaign_bounces_imap_unique');
            $table->index(['email_campaign_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_bounces');
    }
};
