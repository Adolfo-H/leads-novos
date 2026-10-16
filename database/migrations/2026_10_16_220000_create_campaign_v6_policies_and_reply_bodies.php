<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_send_policies', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedSmallInteger('target_volume');
            $table->unsignedTinyInteger('duration_hours');
            $table->string('window_start', 5);
            $table->unsignedSmallInteger('hourly_limit');
            $table->unsignedTinyInteger('per_tick_limit');
            $table->boolean('active')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('capacity_confirmed_at')->nullable();
            $table->timestampsTz();
        });

        // O conteúdo só será lido sob demanda. O Eloquent o criptografa em repouso.
        Schema::table('email_campaign_replies', function (Blueprint $table): void {
            $table->longText('body_text')->nullable();
            $table->timestampTz('body_loaded_at')->nullable();
        });
        Schema::table('email_campaign_test_messages', function (Blueprint $table): void {
            $table->longText('reply_body_text')->nullable();
            $table->timestampTz('reply_body_loaded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_test_messages', function (Blueprint $table): void {
            $table->dropColumn(['reply_body_text', 'reply_body_loaded_at']);
        });
        Schema::table('email_campaign_replies', function (Blueprint $table): void {
            $table->dropColumn(['body_text', 'body_loaded_at']);
        });
        Schema::dropIfExists('email_campaign_send_policies');
    }
};
