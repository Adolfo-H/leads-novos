<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_pacing_v90', static function (Blueprint $table): void {
            $table->foreignId('email_campaign_id')->primary()->constrained('email_campaigns')->cascadeOnDelete();
            $table->unsignedTinyInteger('duration_hours');
            $table->unsignedInteger('hourly_limit');
            $table->unsignedInteger('per_tick_limit');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_pacing_v90');
    }
};
