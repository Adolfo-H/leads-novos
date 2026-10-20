<?php

namespace App\Services\Contacts;

use App\Models\Company;
use App\Support\TextNormalizer;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Contatos CADASTRAIS de estabelecimentos da Receita / base fiscal.
 * Nao sao pessoas e NAO sao adicionados automaticamente ao HubSpot ou a campanhas.
 * A relacao fiscal usa exclusivamente establishments.company_id -> companies.id.
 */
final class ContactReceitaDirectory
{
    /** @return LengthAwarePaginator<int, stdClass> */
    public function search(
        string $term,
        string $kind = 'all',
        string $channel = 'all',
        int $page = 1,
    ): LengthAwarePaginator {
        $term = trim(mb_substr($term, 0, 120));
        $page = max(1, min($page, 100000));

        // Nunca faz um COUNT/SCAN de todos os estabelecimentos sem filtro.
        if (mb_strlen($term) < 2) {
            return $this->emptyPage($page);
        }

        $name = TextNormalizer::companyName($term);
        $digits = (string) preg_replace('/\D+/', '', $term);
        $emailSearch = str_contains($term, '@');
        $companyIds = [];

        if ($name !== null && mb_strlen($name) >= 2 && ! $emailSearch) {
            // Normalizacao original da base: "C.Vale" vira "C VALE".
            // Busca por ID fiscal; NUNCA usa nome/domínio/Deal para vincular um HubSpot.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $name);
            $companyIds = Company::query()
                ->whereRaw("normalized_name LIKE ? ESCAPE '!'", ['%'.$escaped.'%'])
                ->limit(200)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

            // Quem digita "CVALE" tambem deve encontrar "C VALE".
            if ($companyIds === []) {
                $compact = str_replace(' ', '', $name);
                if (mb_strlen($compact) >= 4) {
                    $escapedCompact = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $compact);
                    $companyIds = Company::query()
                        ->whereRaw("REPLACE(normalized_name, ' ', '') LIKE ? ESCAPE '!'", ['%'.$escapedCompact.'%'])
                        ->limit(200)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
                }
            }
        }

        if ($companyIds === [] && strlen($digits) < 8 && ! $emailSearch) {
            return $this->emptyPage($page);
        }

        $query = DB::table('establishments as e')
            ->join('companies as c', 'c.id', '=', 'e.company_id')
            ->select([
                'e.id', 'e.uuid', 'e.company_id', 'e.cnpj', 'e.type',
                'e.fantasy_name', 'e.registration_status',
                'e.registration_status_code', 'e.municipality_name', 'e.state',
                'e.email', 'e.phone_1', 'e.phone_2', 'e.source',
                'e.source_updated_at', 'c.uuid as company_uuid',
                'c.corporate_name', 'c.cnpj_root',
            ])
            ->where(static function (Builder $q): void {
                $q->whereRaw("NULLIF(TRIM(e.email), '') IS NOT NULL")
                    ->orWhereRaw("NULLIF(TRIM(e.phone_1), '') IS NOT NULL")
                    ->orWhereRaw("NULLIF(TRIM(e.phone_2), '') IS NOT NULL");
            })
            ->where(static function (Builder $q) use ($companyIds, $digits, $term, $emailSearch): void {
                if ($companyIds !== []) {
                    $q->whereIn('e.company_id', $companyIds);
                }
                if (strlen($digits) >= 8) {
                    // Numeros de CNPJ/telefone podem conter pontuacao na pesquisa.
                    $q->orWhere('e.cnpj', 'like', '%'.$digits.'%')
                        ->orWhere('c.cnpj_root', 'like', '%'.$digits.'%')
                        ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(e.phone_1, ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '/', ''), '+', '') LIKE ?", ['%'.$digits.'%'])
                        ->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(e.phone_2, ''), '(', ''), ')', ''), '-', ''), ' ', ''), '.', ''), '/', ''), '+', '') LIKE ?", ['%'.$digits.'%']);
                }
                if ($emailSearch) {
                    $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
                    $q->orWhereRaw("LOWER(e.email) LIKE ? ESCAPE '!'", [$like]);
                }
            });

        if (in_array($kind, ['matrix', 'branch'], true)) {
            $query->where('e.type', '=', $kind);
        }
        if ($channel === 'email') {
            $query->whereRaw("NULLIF(TRIM(e.email), '') IS NOT NULL");
        } elseif ($channel === 'phone') {
            $query->where(static function (Builder $q): void {
                $q->whereRaw("NULLIF(TRIM(e.phone_1), '') IS NOT NULL")
                    ->orWhereRaw("NULLIF(TRIM(e.phone_2), '') IS NOT NULL");
            });
        }

        /** @var LengthAwarePaginator<int, stdClass> $results */
        $results = $query
            ->orderBy('c.corporate_name')
            ->orderByRaw("CASE WHEN e.type = 'matrix' THEN 0 ELSE 1 END")
            ->orderBy('e.cnpj')
            ->paginate(20, ['*'], 'receitaPage', $page);

        return $results;
    }

    /** @return LengthAwarePaginator<int, stdClass> */
    private function emptyPage(int $page): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, stdClass> $result */
        $result = new LengthAwarePaginator([], 0, 20, $page);

        return $result;
    }
}
