<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'company_export_intelligences',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->foreignId('company_id')
                    ->unique()
                    ->constrained()
                    ->cascadeOnDelete();

                /*
                 * Exportação direta:
                 * operação própria de exportação.
                 */
                $table
                    ->string(
                        'direct_status',
                        32
                    )
                    ->default(
                        'not_researched'
                    );

                $table
                    ->unsignedSmallInteger(
                        'direct_confidence'
                    )
                    ->default(0);

                $table
                    ->boolean(
                        'direct_confirmed'
                    )
                    ->default(false);

                $table
                    ->text(
                        'direct_summary'
                    )
                    ->nullable();

                /*
                 * Exportação indireta:
                 * venda com fim específico
                 * de exportação.
                 */
                $table
                    ->string(
                        'indirect_status',
                        32
                    )
                    ->default(
                        'not_researched'
                    );

                $table
                    ->unsignedSmallInteger(
                        'indirect_confidence'
                    )
                    ->default(0);

                $table
                    ->boolean(
                        'indirect_confirmed'
                    )
                    ->default(false);

                $table
                    ->text(
                        'indirect_summary'
                    )
                    ->nullable();

                /*
                 * Relação comercial com
                 * trading/comercial exportadora.
                 */
                $table
                    ->string(
                        'trading_status',
                        32
                    )
                    ->default(
                        'not_researched'
                    );

                $table
                    ->unsignedSmallInteger(
                        'trading_confidence'
                    )
                    ->default(0);

                $table
                    ->boolean(
                        'trading_confirmed'
                    )
                    ->default(false);

                $table
                    ->text(
                        'trading_summary'
                    )
                    ->nullable();

                $table
                    ->text('overall_summary')
                    ->nullable();

                $table
                    ->timestampTz(
                        'researched_at'
                    )
                    ->nullable();

                $table
                    ->timestampTz(
                        'reviewed_at'
                    )
                    ->nullable();

                $table
                    ->jsonb('metadata')
                    ->nullable();

                $table->timestampsTz();

                $table->index(
                    'direct_status'
                );

                $table->index(
                    'indirect_status'
                );

                $table->index(
                    'trading_status'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'company_export_intelligences'
        );
    }
};
