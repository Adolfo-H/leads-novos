<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PACOTE 39 — Títulos de tarefas HubSpot não devem ficar menores na
 * projeção comercial do que na origem. TEXT evita perda de assunto.
 *
 * PostgreSQL: varchar -> text não precisa copiar os registros existentes.
 * SQLite de testes: change() é suportado pelo Laravel atual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubspot_activities', static function (Blueprint $table): void {
            $table->text('title')->nullable()->change();
        });

        Schema::table('company_lead_activities', static function (Blueprint $table): void {
            $table->text('title')->change();
        });
    }

    public function down(): void
    {
        // Nunca reduzir silenciosamente VARCHAR com títulos já salvos maiores.
        if (
            DB::table('hubspot_activities')->whereRaw('LENGTH(title) > 180')->exists()
            || DB::table('company_lead_activities')->whereRaw('LENGTH(title) > 150')->exists()
        ) {
            throw new RuntimeException(
                'Rollback bloqueado: há títulos acima dos limites anteriores. '
                .'Não reduza os campos sem tratar os registros explicitamente.'
            );
        }

        Schema::table('company_lead_activities', static function (Blueprint $table): void {
            $table->string('title', 150)->change();
        });

        Schema::table('hubspot_activities', static function (Blueprint $table): void {
            $table->string('title', 180)->nullable()->change();
        });
    }
};
