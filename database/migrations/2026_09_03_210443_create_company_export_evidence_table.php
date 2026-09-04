<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'company_export_evidence',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId('company_id')
                    ->constrained()
                    ->cascadeOnDelete();

                /*
                 * direct
                 * indirect
                 * trading
                 */
                $table->string(
                    'dimension',
                    32
                );

                /*
                 * positive
                 * negative
                 * neutral
                 */
                $table->string(
                    'signal',
                    16
                );

                /*
                 * Exemplos:
                 *
                 * company_site
                 * government
                 * news
                 * crm
                 * manual
                 * other
                 */
                $table->string(
                    'source_type',
                    50
                );

                $table
                    ->string('source_name')
                    ->nullable();

                $table
                    ->text('source_url')
                    ->nullable();

                $table
                    ->string('title')
                    ->nullable();

                $table->text(
                    'evidence_text'
                );

                $table
                    ->unsignedSmallInteger(
                        'confidence'
                    )
                    ->default(50);

                /*
                 * Exemplo:
                 *
                 * cliente respondeu diretamente
                 * que realiza exportação indireta.
                 */
                $table
                    ->boolean(
                        'is_confirmed'
                    )
                    ->default(false);

                $table
                    ->timestampTz(
                        'observed_at'
                    )
                    ->nullable();

                $table
                    ->jsonb('metadata')
                    ->nullable();

                $table->timestampsTz();

                $table->index([
                    'company_id',
                    'dimension',
                ]);

                $table->index([
                    'dimension',
                    'signal',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'company_export_evidence'
        );
    }
};
