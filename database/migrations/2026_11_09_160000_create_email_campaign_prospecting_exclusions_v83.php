<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_prospecting_exclusions_v83', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('normalized_email', 254);
            $table->timestampsTz();
            $table->unique(['user_id', 'normalized_email'], 'ec_prospect_v83_user_email_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_prospecting_exclusions_v83');
    }
};
