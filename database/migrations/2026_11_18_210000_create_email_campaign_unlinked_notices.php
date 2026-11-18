<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_unlinked_notices', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('candidate_campaign_id')->nullable()
                ->constrained('email_campaigns')->nullOnDelete();
            $table->string('mailbox', 255);
            $table->string('uid_validity', 40);
            $table->unsignedBigInteger('imap_uid');
            $table->string('from_email', 255);
            $table->string('subject', 255);
            $table->string('notice_type', 32);
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();
            $table->unique(['mailbox', 'uid_validity', 'imap_uid'], 'ec_unlinked_notice_uid_unique');
            $table->index(['candidate_campaign_id', 'received_at'], 'ec_unlinked_notice_campaign_recent');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_unlinked_notices');
    }
};
