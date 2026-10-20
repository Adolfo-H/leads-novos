<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubspot_company_contact', function (Blueprint $table): void {
            // O índice único existente começa por hubspot_company_id.
            // Este atende a busca inversa: contatos -> empresas.
            $table->index(
                ['hubspot_contact_id', 'hubspot_company_id'],
                'hcc_contact_company_lookup_idx',
            );
        });

        Schema::table('hubspot_deal_contact', function (Blueprint $table): void {
            // Negócios consultados a partir de uma pessoa.
            $table->index(
                ['hubspot_contact_id', 'hubspot_deal_id'],
                'hdc_contact_deal_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('hubspot_deal_contact', function (Blueprint $table): void {
            $table->dropIndex('hdc_contact_deal_lookup_idx');
        });
        Schema::table('hubspot_company_contact', function (Blueprint $table): void {
            $table->dropIndex('hcc_contact_company_lookup_idx');
        });
    }
};
