<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_access_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 160);
            $table->string('email', 254)->unique();
            $table->string('company', 180);
            $table->string('phone', 40)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('consent_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->char('invitation_token_hash', 64)->nullable()->unique();
            $table->timestamp('invitation_expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('notification_status', 24)->default('pending');
            $table->timestamp('notification_attempted_at')->nullable();
            $table->string('invitation_status', 24)->nullable();
            $table->timestamp('invitation_attempted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_access_requests');
    }
};
