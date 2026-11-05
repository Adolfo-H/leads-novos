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
            $table->foreignId('followup_root_campaign_id')->nullable()
                ->constrained('email_campaigns')->nullOnDelete();
            $table->unsignedSmallInteger('followup_round')->default(1);
            $table->index(['followup_root_campaign_id', 'followup_round'], 'campaign_followup_round_index');
        });

        Schema::table('email_campaign_sequences', function (Blueprint $table): void {
            $table->boolean('automatic')->default(false);
            $table->unsignedSmallInteger('max_rounds')->default(1);
            $table->unsignedSmallInteger('sent_rounds')->default(0);
            $table->foreignId('last_campaign_id')->nullable()
                ->constrained('email_campaigns')->nullOnDelete();
            $table->timestampTz('automatic_authorized_at')->nullable();
            $table->index(['automatic', 'status'], 'campaign_auto_sequence_index');
        });
        // Evita duas sequências simultâneas para a mesma campanha por duplo clique.
        // PostgreSQL e SQLite aceitam índices únicos parciais.
        DB::statement("CREATE UNIQUE INDEX campaign_auto_active_root_unique ON email_campaign_sequences (email_campaign_id) WHERE automatic = true AND status IN ('active', 'paused')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS campaign_auto_active_root_unique');
        Schema::table('email_campaign_sequences', function (Blueprint $table): void {
            $table->dropIndex('campaign_auto_sequence_index');
            $table->dropForeign(['last_campaign_id']);
            $table->dropColumn(['automatic', 'max_rounds', 'sent_rounds', 'last_campaign_id', 'automatic_authorized_at']);
        });
        Schema::table('email_campaigns', function (Blueprint $table): void {
            $table->dropIndex('campaign_followup_round_index');
            $table->dropForeign(['followup_root_campaign_id']);
            $table->dropColumn(['followup_root_campaign_id', 'followup_round']);
        });
    }
};
