<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        /*
         * O sistema já possuía usuários antes
         * da verificação de e-mail passar a ser
         * realmente aplicada pelo middleware.
         *
         * Preservamos o acesso dessas contas
         * internas existentes.
         */
        DB::table('users')
            ->whereNull(
                'email_verified_at'
            )
            ->update([
                'email_verified_at' => now(),
            ]);
    }

    public function down(): void
    {
        /*
         * Não revertemos email_verified_at:
         * não é possível saber com segurança
         * quais contas já eram verificadas.
         */
    }
};
