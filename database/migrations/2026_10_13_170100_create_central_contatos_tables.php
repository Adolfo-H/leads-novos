<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            // ID externo estável mesmo depois de substituir o espelho HubSpot.
            $table->string('hubspot_company_external_id', 100)->nullable()->index();
            $table->string('name', 180);
            $table->string('job_title', 180)->nullable();
            $table->string('email', 255)->nullable()->index();
            $table->string('phone', 80)->nullable();
            $table->string('mobile_phone', 80)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['company_id', 'name']);
        });

        Schema::create('contact_phone_verifications', function (Blueprint $table): void {
            $table->id();
            // hubspot:{ID externo}, manual:{ID local}, receita:{UUID estabelecimento}
            $table->string('source_key', 160);
            $table->string('phone_normalized', 24);
            $table->string('whatsapp_status', 16)->default('unknown');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
            $table->unique(['source_key', 'phone_normalized'], 'contact_phone_verifications_unique');
            $table->index('whatsapp_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_phone_verifications');
        Schema::dropIfExists('commercial_contacts');
    }
};
