<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_proposals', function (Blueprint $table): void {
            $table->unsignedInteger('dfe_cnpj_count')->nullable();
            $table->unsignedInteger('dfe_total')->nullable();
            $table->unsignedInteger('nfse_cnpj_count')->nullable();
            $table->unsignedInteger('nfse_total')->nullable();
        });
    }

    public function down(): void
    {
        // Never drop populated fields implicitly during application rollback.
        if (DB::table('ec_proposals')->where(function ($q): void {
            $q->whereNotNull('dfe_cnpj_count')->orWhereNotNull('dfe_total')->orWhereNotNull('nfse_cnpj_count')->orWhereNotNull('nfse_total');
        })->exists()) {
            throw new RuntimeException('Existem dados DF-e/NFS-e. Restauração destrutiva bloqueada.');
        }
        Schema::table('ec_proposals', function (Blueprint $table): void {
            $table->dropColumn(['dfe_cnpj_count', 'dfe_total', 'nfse_cnpj_count', 'nfse_total']);
        });
    }
};
