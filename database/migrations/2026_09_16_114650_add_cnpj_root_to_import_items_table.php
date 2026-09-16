<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'import_items',
            function (Blueprint $table): void {
                $table
                    ->string(
                        'cnpj_root',
                        8
                    )
                    ->nullable()
                    ->index();
            }
        );

        /*
         * Preenche também os itens históricos.
         *
         * normalized_cnpj já está salvo em
         * formato canônico, então os oito
         * primeiros caracteres representam
         * a raiz do CNPJ.
         */
        DB::table('import_items')
            ->whereNotNull(
                'normalized_cnpj'
            )
            ->whereNull(
                'cnpj_root'
            )
            ->update([
                'cnpj_root' => DB::raw(
                    'substr(normalized_cnpj, 1, 8)'
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table(
            'import_items',
            function (Blueprint $table): void {
                $table->dropIndex([
                    'cnpj_root',
                ]);

                $table->dropColumn(
                    'cnpj_root'
                );
            }
        );
    }
};
