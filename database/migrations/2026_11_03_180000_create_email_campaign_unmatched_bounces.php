<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_unmatched_bounces', function (Blueprint $table): void {
            $table->id();
            $table->string('mailbox', 255);
            $table->string('uid_validity', 40);
            $table->unsignedBigInteger('imap_uid');
            $table->string('recipient_mask', 255)->nullable();
            $table->string('status_code', 16);
            $table->string('action', 16);
            $table->string('classification', 32);
            $table->timestampTz('received_at')->nullable();
            $table->timestampsTz();
            $table->unique(['mailbox', 'uid_validity', 'imap_uid'], 'campaign_unmatched_imap_unique');
            $table->index(['received_at', 'id'], 'campaign_unmatched_received_index');
        });
        Schema::table('email_campaign_sequences', function (Blueprint $table): void {
            $table->boolean('auto_prepare')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_sequences', function (Blueprint $table): void {
            $table->dropColumn('auto_prepare');
        });
        Schema::dropIfExists('email_campaign_unmatched_bounces');
    }
};
