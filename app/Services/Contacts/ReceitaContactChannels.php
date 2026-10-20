<?php

namespace App\Services\Contacts;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Canais institucionais declarados nos estabelecimentos da Receita.
 * Cada linha pertence a um CNPJ real e NÃO representa uma pessoa física.
 * Não cruza empresas por nome, domínio ou vínculos indiretos do HubSpot.
 */
final class ReceitaContactChannels
{
    public static function query(): Builder
    {
        return DB::table('establishments as e')
            ->join('companies as rc', 'rc.id', '=', 'e.company_id')
            ->where(static function (Builder $q): void {
                $q->whereRaw("NULLIF(TRIM(COALESCE(e.email, '')), '') IS NOT NULL")
                    ->orWhereRaw("NULLIF(TRIM(COALESCE(e.phone_1, '')), '') IS NOT NULL")
                    ->orWhereRaw("NULLIF(TRIM(COALESCE(e.phone_2, '')), '') IS NOT NULL");
            });
    }

    public static function withEmail(): Builder
    {
        return self::query()
            ->whereRaw("NULLIF(TRIM(COALESCE(e.email, '')), '') IS NOT NULL");
    }
}
