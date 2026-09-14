<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ProspectingDiscoveryService
{
    /**
     * @param  list<string>|null  $states
     * @param  list<string>|null  $cnaes
     * @param  list<string>|null  $sizeCodes
     * @return array{
     *     items: list<array{
     *         cnpj_root: string,
     *         cnpj: string,
     *         corporate_name: string,
     *         state: string|null,
     *         matched_cnae: string|null,
     *         primary_cnae: string|null,
     *         share_capital: float|null,
     *         size_code: string|null,
     *         legal_nature_code: string|null,
     *         cnae_match_type: string|null,
     *         active_establishments: int,
     *         active_states: int,
     *         discovery_score: int|null
     *     }>,
     *     count: int,
     *     limit: int,
     *     offset: int,
     *     filters: array<string, mixed>
     * }
     */
    public function discover(
        int $limit = 100,
        int $offset = 0,
        ?array $states = null,
        ?array $cnaes = null,
        ?float $minCapital = null,
        ?array $sizeCodes = null,
    ): array {
        $limit =
            max(
                1,
                min(
                    1000,
                    $limit
                )
            );

        $offset =
            max(
                0,
                $offset
            );

        $query = [
            'limit' => $limit,
            'offset' => $offset,
        ];

        if (
            $states !== null
            && $states !== []
        ) {
            $query['states'] =
                implode(
                    ',',
                    $states
                );
        }

        if (
            $cnaes !== null
            && $cnaes !== []
        ) {
            $query['cnaes'] =
                implode(
                    ',',
                    $cnaes
                );
        }

        if (
            $minCapital !== null
            && $minCapital > 0
        ) {
            $query['min_capital'] =
                $minCapital;
        }

        if (
            $sizeCodes !== null
            && $sizeCodes !== []
        ) {
            $query['size_codes'] =
                implode(
                    ',',
                    $sizeCodes
                );
        }

        $baseUrl =
            rtrim(
                (string) config(
                    'services.receita_local.base_url'
                ),
                '/'
            );

        try {
            $response =
                Http::acceptJson()
                    ->connectTimeout(5)
                    ->timeout(120)
                    ->get(
                        $baseUrl.'/prospects',
                        $query,
                    );
        } catch (
            ConnectionException $exception
        ) {
            throw new RuntimeException(
                'Não foi possível conectar ao '
                .'motor local da Receita.',
                previous: $exception,
            );
        }

        if ($response->status() === 422) {
            throw new RuntimeException(
                'Filtros inválidos para descoberta '
                .'de prospects: '
                .$response->body()
            );
        }

        if ($response->status() === 503) {
            throw new RuntimeException(
                'A base local da Receita não está '
                .'disponível para prospecção.'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Erro ao consultar motor de '
                .'prospecção. HTTP '
                .$response->status()
                .'.'
            );
        }

        $data =
            $response->json();

        if (! is_array($data)) {
            throw new RuntimeException(
                'O motor de prospecção retornou '
                .'uma resposta inválida.'
            );
        }

        $rawItems =
            $data['items']
            ?? [];

        if (! is_array($rawItems)) {
            $rawItems = [];
        }

        $items = [];

        foreach ($rawItems as $rawItem) {
            if (! is_array($rawItem)) {
                continue;
            }

            $root =
                trim(
                    (string) (
                        $rawItem[
                            'cnpj_root'
                        ]
                        ?? ''
                    )
                );

            $cnpj =
                trim(
                    (string) (
                        $rawItem[
                            'cnpj'
                        ]
                        ?? ''
                    )
                );

            $name =
                trim(
                    (string) (
                        $rawItem[
                            'corporate_name'
                        ]
                        ?? ''
                    )
                );

            if (
                $root === ''
                || $cnpj === ''
                || $name === ''
            ) {
                continue;
            }

            $items[] = [
                'cnpj_root' => $root,

                'cnpj' => $cnpj,

                'corporate_name' => $name,

                'state' => $this->nullableString(
                    $rawItem[
                        'state'
                    ]
                    ?? null
                ),

                'matched_cnae' => $this->nullableString(
                    $rawItem[
                        'matched_cnae'
                    ]
                    ?? null
                ),

                'primary_cnae' => $this->nullableString(
                    $rawItem[
                        'primary_cnae'
                    ]
                    ?? null
                ),

                'share_capital' => is_numeric(
                    $rawItem[
                        'share_capital'
                    ]
                    ?? null
                )
                        ? (float) $rawItem[
                            'share_capital'
                        ]
                        : null,

                'size_code' => $this->nullableString(
                    $rawItem[
                        'size_code'
                    ]
                    ?? null
                ),

                'legal_nature_code' => $this->nullableString(
                    $rawItem[
                        'legal_nature_code'
                    ]
                    ?? null
                ),
                'cnae_match_type' => $this->nullableString(
                    $rawItem[
                        'cnae_match_type'
                    ]
                    ?? null
                ),

                'active_establishments' => is_numeric(
                    $rawItem[
                        'active_establishments'
                    ]
                    ?? null
                )
                        ? (int) $rawItem[
                            'active_establishments'
                        ]
                        : 0,

                'active_states' => is_numeric(
                    $rawItem[
                        'active_states'
                    ]
                    ?? null
                )
                        ? (int) $rawItem[
                            'active_states'
                        ]
                        : 0,

                'discovery_score' => is_numeric(
                    $rawItem[
                        'discovery_score'
                    ]
                    ?? null
                )
                        ? (int) $rawItem[
                            'discovery_score'
                        ]
                        : null,

            ];
        }

        $filters =
            $data['filters']
            ?? [];

        return [
            'items' => $items,

            'count' => count(
                $items
            ),

            'limit' => (int) (
                $data['limit']
                ?? $limit
            ),

            'offset' => (int) (
                $data['offset']
                ?? $offset
            ),

            'filters' => is_array($filters)
                    ? $filters
                    : [],
        ];
    }

    private function nullableString(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value =
            trim(
                $value
            );

        return $value !== ''
            ? $value
            : null;
    }
}
