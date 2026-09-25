<?php

namespace App\Services;

use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class HubSpotDealCompanyAssociationService
{
    /**
     * IDs de associação que representam
     * "Primary Company" no portal atual.
     *
     * @var list<int>|null
     */
    private ?array $primaryTypeIds = null;

    /**
     * Sincroniza TODAS as Companies do Deal,
     * preservando a associação real do HubSpot,
     * mas marca somente a Company comercial.
     *
     * Regra:
     *
     * - 1 Company:
     *   considera Primary por fallback seguro.
     *
     * - várias Companies:
     *   exige exatamente uma associação Primary.
     *
     * - várias sem Primary / várias Primary:
     *   nenhuma recebe o negócio comercialmente.
     *
     * @return array{
     *     associations: int,
     *     primary: int,
     *     ambiguous: bool
     * }
     */
    public function syncForDeal(
        HubSpotDeal $deal
    ): array {
        $associations =
            $this->associationRows(
                (string) $deal->hubspot_id
            );

        $primaryTypeIds =
            count($associations) > 1
                ? $this->primaryTypeIds()
                : [];

        /**
         * @var array<int, array{is_primary: bool}>
         */
        $payload = [];

        $primaryCount = 0;

        foreach (
            $associations as $association
        ) {
            $externalId =
                $this->associationObjectId(
                    $association
                );

            if ($externalId === null) {
                continue;
            }

            /*
             * Company do HubSpot pode existir
             * mesmo sem CNPJ identificado.
             *
             * Isso é proposital.
             */
            $hubSpotCompany =
                HubSpotCompany::query()
                    ->firstOrCreate([
                        'hubspot_id' => $externalId,
                    ]);

            $isPrimary =
                $this->associationIsPrimary(
                    association: $association,

                    primaryTypeIds: $primaryTypeIds,
                );

            if ($isPrimary) {
                $primaryCount++;
            }

            $payload[
                (int) $hubSpotCompany->id
            ] = [
                'is_primary' => $isPrimary,
            ];
        }

        /*
         * Uma única Company associada ao Deal
         * não possui ambiguidade.
         */
        if (
            count($payload) === 1
        ) {
            $companyId =
                array_key_first(
                    $payload
                );

            $payload[
                $companyId
            ][
                'is_primary'
            ] = true;

            $primaryCount = 1;
        }

        $ambiguous =
            count($payload) > 1
            && $primaryCount !== 1;

        /*
         * Segurança máxima:
         *
         * Deal múltiplo precisa possuir
         * exatamente UMA Primary Company.
         *
         * Se não existir ou vier mais de uma,
         * nenhuma será tratada comercialmente.
         */
        if ($ambiguous) {
            foreach (
                array_keys(
                    $payload
                ) as $companyId
            ) {
                $payload[
                    $companyId
                ][
                    'is_primary'
                ] = false;
            }

            $primaryCount = 0;
        }

        $deal
            ->companies()
            ->sync(
                $payload
            );

        return [
            'associations' => count($payload),

            'primary' => $primaryCount,

            'ambiguous' => $ambiguous,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function associationRows(
        string $dealId
    ): array {
        $rows = [];

        $after = null;

        $seenAfter = [];

        do {
            $query = [
                'limit' => 100,
            ];

            if ($after !== null) {
                $query[
                    'after'
                ] =
                    $after;
            }

            try {
                $response =
                    $this
                        ->client()
                        ->get(
                            $this->baseUrl()
                            .'/crm/v4/objects/deals/'
                            .rawurlencode(
                                $dealId
                            )
                            .'/associations/companies',
                            $query
                        );
            } catch (
                ConnectionException $exception
            ) {
                throw new RuntimeException(
                    'Não foi possível consultar '
                    .'Companies associadas ao Deal.',
                    previous: $exception,
                );
            }

            if (
                $response->status()
                === 404
            ) {
                return [];
            }

            if ($response->failed()) {
                throw new RuntimeException(
                    'Falha ao consultar Companies '
                    .'do Deal no HubSpot. HTTP '
                    .$response->status()
                    .'.'
                );
            }

            $data =
                $response->json();

            if (! is_array($data)) {
                throw new RuntimeException(
                    'Resposta inválida das associações '
                    .'Deal -> Company.'
                );
            }

            $results =
                $data[
                    'results'
                ]
                ?? [];

            if (is_array($results)) {
                foreach (
                    $results as $result
                ) {
                    if (
                        is_array(
                            $result
                        )
                    ) {
                        $rows[] =
                            $result;
                    }
                }
            }

            $next =
                data_get(
                    $data,
                    'paging.next.after'
                );

            $next =
                is_scalar($next)
                    ? trim(
                        (string) $next
                    )
                    : '';

            if (
                $next === ''
                || isset(
                    $seenAfter[
                        $next
                    ]
                )
            ) {
                $after = null;
            } else {
                $seenAfter[
                    $next
                ] = true;

                $after =
                    $next;
            }

        } while (
            $after !== null
        );

        return $rows;
    }

    /**
     * Descobre os typeIds de Primary Company
     * existentes no portal.
     *
     * Depois disso, as associações são
     * comparadas por ID, não pelo label.
     *
     * @return list<int>
     */
    private function primaryTypeIds(): array
    {
        if (
            $this->primaryTypeIds
            !== null
        ) {
            return $this->primaryTypeIds;
        }

        try {
            $response =
                $this
                    ->client()
                    ->get(
                        $this->baseUrl()
                        .'/crm/v4/associations/'
                        .'deals/companies/labels'
                    );
        } catch (
            ConnectionException
        ) {
            /*
             * Não transformamos falha dessa
             * consulta em associação incorreta.
             *
             * associationIsPrimary ainda pode
             * reconhecer o label retornado na
             * própria associação.
             */
            return
                $this->primaryTypeIds =
                    [];
        }

        if ($response->failed()) {
            return
                $this->primaryTypeIds =
                    [];
        }

        $data =
            $response->json();

        $results =
            is_array($data)
                ? (
                    $data[
                        'results'
                    ]
                    ?? []
                )
                : [];

        $ids = [];

        if (is_array($results)) {
            foreach (
                $results as $result
            ) {
                if (! is_array($result)) {
                    continue;
                }

                $typeId =
                    $result[
                        'typeId'
                    ]
                    ?? $result[
                        'associationTypeId'
                    ]
                    ?? null;

                if (
                    ! is_numeric(
                        $typeId
                    )
                ) {
                    continue;
                }

                $label =
                    $this->normalizeLabel(
                        $result[
                            'label'
                        ]
                        ?? null
                    );

                if (
                    $this
                        ->looksLikePrimaryLabel(
                            $label
                        )
                ) {
                    $ids[] =
                        (int) $typeId;
                }
            }
        }

        return
            $this->primaryTypeIds =
                array_values(
                    array_unique(
                        $ids
                    )
                );
    }

    /**
     * @param  array<string, mixed>  $association
     * @param  list<int>  $primaryTypeIds
     */
    private function associationIsPrimary(
        array $association,
        array $primaryTypeIds,
    ): bool {
        $types =
            $association[
                'associationTypes'
            ]
            ?? $association[
                'types'
            ]
            ?? [];

        if (! is_array($types)) {
            return false;
        }

        foreach (
            $types as $type
        ) {
            if (! is_array($type)) {
                continue;
            }

            $typeId =
                $type[
                    'typeId'
                ]
                ?? $type[
                    'associationTypeId'
                ]
                ?? null;

            if (
                is_numeric(
                    $typeId
                )
                && in_array(
                    (int) $typeId,
                    $primaryTypeIds,
                    true
                )
            ) {
                return true;
            }

            /*
             * Fallback somente para descoberta.
             *
             * Não persistimos dependência do
             * texto do label.
             */
            $label =
                $this->normalizeLabel(
                    $type[
                        'label'
                    ]
                    ?? null
                );

            if (
                $this
                    ->looksLikePrimaryLabel(
                        $label
                    )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $association
     */
    private function associationObjectId(
        array $association
    ): ?string {
        $id =
            $association[
                'toObjectId'
            ]
            ?? $association[
                'id'
            ]
            ?? null;

        if (! is_scalar($id)) {
            return null;
        }

        $id =
            trim(
                (string) $id
            );

        return
            $id !== ''
                ? $id
                : null;
    }

    private function normalizeLabel(
        mixed $value
    ): string {
        if (! is_scalar($value)) {
            return '';
        }

        return mb_strtolower(
            Str::ascii(
                trim(
                    (string) $value
                )
            )
        );
    }

    private function looksLikePrimaryLabel(
        string $label
    ): bool {
        if ($label === '') {
            return false;
        }

        return
            str_contains(
                $label,
                'primary'
            )
            || str_contains(
                $label,
                'principal'
            );
    }

    private function client(): PendingRequest
    {
        $token =
            trim(
                (string) config(
                    'services.hubspot.access_token',
                    ''
                )
            );

        if ($token === '') {
            throw new RuntimeException(
                'HUBSPOT_ACCESS_TOKEN não configurado.'
            );
        }

        return Http::withToken(
            $token
        )
            ->acceptJson()
            ->connectTimeout(
                5
            )
            ->timeout(
                30
            );
    }

    private function baseUrl(): string
    {
        return rtrim(
            (string) config(
                'services.hubspot.base_url',
                'https://api.hubapi.com'
            ),
            '/'
        );
    }
}
