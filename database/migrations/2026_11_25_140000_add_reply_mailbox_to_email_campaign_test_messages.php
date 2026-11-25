<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaign_test_messages', function (Blueprint $table): void {
            $table->string('reply_mailbox', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_test_messages', function (Blueprint $table): void {
            $table->dropColumn('reply_mailbox');
        });
    }
};
