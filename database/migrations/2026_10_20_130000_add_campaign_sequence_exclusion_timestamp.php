<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaign_recipients', function (Blueprint $table): void {
            $table->timestampTz('excluded_from_sequences_at')->nullable();
            $table->index(
                ['email_campaign_id', 'excluded_from_sequences_at'],
                'campaign_sequence_exclusion_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_recipients', function (Blueprint $table): void {
            $table->dropIndex('campaign_sequence_exclusion_index');
            $table->dropColumn('excluded_from_sequences_at');
        });
    }
};
