<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table): void {
            $table->string('sender_mode', 16)->default('legacy');
        });
        Schema::table('email_campaign_recipients', function (Blueprint $table): void {
            $table->string('sender_profile', 32)->nullable()->index();
            $table->string('sender_address', 255)->nullable();
        });
        Schema::create('email_campaign_sender_control', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->boolean('dual_enabled')->default(false);
            $table->timestampTz('authorized_at')->nullable();
            $table->string('authorization_reference', 200)->nullable();
            $table->timestampTz('blocked_at')->nullable();
            $table->string('blocked_reason', 100)->nullable();
        });
        DB::table('email_campaign_sender_control')->insert(['id' => 1, 'dual_enabled' => false]);
    }

    public function down(): void
    {
        if (DB::table('email_campaign_recipients')->whereNotNull('sender_profile')->exists()) {
            throw new RuntimeException('Não remova o histórico de remetentes já utilizado.');
        }
        Schema::dropIfExists('email_campaign_sender_control');
        Schema::table('email_campaign_recipients', function (Blueprint $table): void {
            $table->dropIndex(['sender_profile']);
            $table->dropColumn(['sender_profile', 'sender_address']);
        });
        Schema::table('email_campaigns', function (Blueprint $table): void {
            $table->dropColumn('sender_mode');
        });
    }
};
