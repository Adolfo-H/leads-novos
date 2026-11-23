<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_campaign_inbound_notices')) {
            throw new RuntimeException('Instale a correção IMAP V2.1 antes desta migration.');
        }

        Schema::table('email_campaign_inbound_notices', static function (Blueprint $table): void {
            $table->timestampTz('promoted_to_reply_at')->nullable();
            $table->unsignedBigInteger('promoted_by_user_id')->nullable();
            $table->unsignedBigInteger('promoted_reply_id')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('email_campaign_inbound_notices')) {
            Schema::table('email_campaign_inbound_notices', static function (Blueprint $table): void {
                $table->dropColumn([
                    'promoted_to_reply_at', 'promoted_by_user_id', 'promoted_reply_id',
                ]);
            });
        }
    }
};
