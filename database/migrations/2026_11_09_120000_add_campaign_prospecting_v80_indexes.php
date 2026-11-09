<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A regra de deduplicação/bloqueio compara endereços em minúsculas.
        // PostgreSQL e SQLite aceitam índices de expressão e IF NOT EXISTS.
        DB::statement('CREATE INDEX IF NOT EXISTS ec80_hubspot_email_norm_idx ON hubspot_contacts (LOWER(TRIM(email)))');
        DB::statement('CREATE INDEX IF NOT EXISTS ec80_manual_email_norm_idx ON commercial_contacts (LOWER(TRIM(email)))');
        DB::statement('CREATE INDEX IF NOT EXISTS ec80_campaign_email_status_idx ON email_campaign_recipients (normalized_email, status, attempted_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS ec80_customer_registry_root_idx ON customer_registry_entries (cnpj_root, enabled)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ec80_customer_registry_root_idx');
        DB::statement('DROP INDEX IF EXISTS ec80_campaign_email_status_idx');
        DB::statement('DROP INDEX IF EXISTS ec80_manual_email_norm_idx');
        DB::statement('DROP INDEX IF EXISTS ec80_hubspot_email_norm_idx');
    }
};
