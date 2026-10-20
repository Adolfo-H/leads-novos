<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaign_release_reviews', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedSmallInteger('smtp_per_hour');
            $table->unsignedSmallInteger('smtp_per_day');
            $table->string('smtp_evidence', 500);
            $table->boolean('smtp_confirmed')->default(false);
            $table->boolean('public_tested')->default(false);
            $table->boolean('audience_reviewed')->default(false);
            $table->boolean('bounce_reviewed')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_release_reviews');
    }
};
