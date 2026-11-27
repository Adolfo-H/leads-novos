<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only approved, explicitly selected records survive the preview.
        Schema::create('email_campaign_import_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('wizard_id');
            $table->string('email', 255);
            $table->string('person_name', 180)->default('');
            $table->string('company_name', 255)->default('');
            $table->string('job_title', 180)->default('');
            $table->string('email_type', 16);
            $table->string('source_label', 240);
            $table->string('file_label', 180);
            $table->text('permission_reference'); // Encrypted, not sent to HubSpot.
            $table->timestampTz('reviewed_at');
            $table->timestampsTz();
            $table->unique(['owner_id', 'wizard_id', 'email'], 'ec_import_owner_wizard_email_unique');
            $table->index(['owner_id', 'wizard_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_import_contacts');
    }
};
