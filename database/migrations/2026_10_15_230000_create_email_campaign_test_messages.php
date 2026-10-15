<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_test_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->string('test_to', 255);
            $table->string('message_id', 255)->unique();
            $table->string('status', 24)->default('initiated');
            $table->timestampTz('attempted_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('replied_at')->nullable();
            $table->string('reply_subject', 255)->nullable();
            $table->string('reply_uid_validity', 40)->nullable();
            $table->unsignedBigInteger('reply_imap_uid')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['email_campaign_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_test_messages');
    }
};
