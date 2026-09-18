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
            'users',
            function (Blueprint $table): void {
                /*
                 * Novos usuários começam como vendedor.
                 *
                 * Usuários já existentes são promovidos
                 * para gestor logo abaixo, preservando
                 * o acesso atual do sistema.
                 */
                $table
                    ->string(
                        'commercial_role',
                        20
                    )
                    ->default(
                        'seller'
                    )
                    ->index();
            }
        );

        DB::table(
            'users'
        )->update([
            'commercial_role' => 'manager',
        ]);
    }

    public function down(): void
    {
        Schema::table(
            'users',
            function (Blueprint $table): void {
                $table->dropColumn(
                    'commercial_role'
                );
            }
        );
    }
};
