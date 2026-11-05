<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Um telefone publicado = um canal cadastral, NÃO uma pessoa física.
        // O vínculo comercial exige Company do HubSpot com o domínio explicitamente cadastrado.
        Schema::create('whatsapp_public_company_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evidence_id')->constrained('whatsapp_public_site_evidences')->cascadeOnDelete();
            $table->foreignId('hubspot_company_id')->constrained('hubspot_companies')->cascadeOnDelete();
            // Vínculo local apenas quando já considerado fiscalmente confiável pelo Prospector.
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('e164', 16);
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();
            $table->unique(['evidence_id', 'hubspot_company_id'], 'wa_public_channel_evidence_company_unique');
            $table->index(['hubspot_company_id', 'last_seen_at'], 'wa_public_channel_company_recent');
            $table->index('e164', 'wa_public_channel_number_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_public_company_channels');
    }
};
