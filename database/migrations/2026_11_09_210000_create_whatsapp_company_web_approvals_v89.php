<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_company_web_approved_v89', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finding_id')
                ->constrained('whatsapp_company_web_findings_v87')->restrictOnDelete();
            $table->string('target_type', 12); // hubspot | local
            $table->unsignedBigInteger('target_id');
            $table->string('company_label', 180);
            $table->string('phone_e164', 16);
            $table->string('note', 300)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at');
            $table->timestampsTz();
            $table->unique(['target_type', 'target_id', 'phone_e164'], 'wa89_approval_unique');
            $table->index(['target_type', 'target_id'], 'wa89_company_approvals');
            $table->index('phone_e164', 'wa89_phone_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_company_web_approved_v89');
    }
};
