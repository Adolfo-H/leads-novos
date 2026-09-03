<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'company_crm_checks',
            function (Blueprint $table) {
                $table->id();

                $table
                    ->foreignId('company_id')
                    ->unique()
                    ->constrained()
                    ->cascadeOnDelete();

                /*
                 * Fonte consultada.
                 * Ex.: hubspot
                 */
                $table
                    ->string('provider', 50);

                /*
                 * not_found
                 * known
                 * prospected
                 * opportunity
                 * client
                 */
                $table
                    ->string('status', 40);

                /*
                 * Identificador do registro
                 * no CRM externo.
                 */
                $table
                    ->string('external_id')
                    ->nullable();

                $table
                    ->string('external_name')
                    ->nullable();

                $table
                    ->string('external_domain')
                    ->nullable();

                /*
                 * Lifecycle Stage original
                 * retornado pelo HubSpot.
                 */
                $table
                    ->string(
                        'lifecycle_stage',
                        80
                    )
                    ->nullable();

                /*
                 * Owner do registro no CRM.
                 */
                $table
                    ->string(
                        'owner_external_id'
                    )
                    ->nullable();

                $table
                    ->unsignedInteger(
                        'contacted_count'
                    )
                    ->default(0);

                $table
                    ->unsignedInteger(
                        'associated_deals_count'
                    )
                    ->default(0);

                $table
                    ->timestampTz(
                        'last_contacted_at'
                    )
                    ->nullable();

                /*
                 * Como o Prospector decidiu
                 * que era a mesma empresa.
                 *
                 * domain | name
                 */
                $table
                    ->string(
                        'matched_by',
                        50
                    )
                    ->nullable();

                $table
                    ->string('matched_value')
                    ->nullable();

                $table
                    ->text('external_url')
                    ->nullable();

                /*
                 * Guarda informações auxiliares
                 * sem engessar o schema.
                 */
                $table
                    ->jsonb('metadata')
                    ->nullable();

                $table
                    ->timestampTz('checked_at');

                $table->timestampsTz();

                $table->index([
                    'status',
                    'checked_at',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'company_crm_checks'
        );
    }
};
