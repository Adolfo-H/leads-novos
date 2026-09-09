<?php

namespace App\Services\Providers;

use App\Contracts\CrmCompanyProvider;
use App\Models\Company;
use App\Support\TextNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HubSpotCrmCompanyProvider implements CrmCompanyProvider
{
    /**
     * @var list<string>
     */
    private const PROPERTIES = [
        'name',
        'domain',
        'lifecyclestage',
        'hubspot_owner_id',
        'num_contacted_notes',
        'num_associated_deals',
        'notes_last_contacted',
    ];

    /**
     * @var list<string>
     */
    /**
     * @var list<string>
     */
    private const DEAL_PROPERTIES = [
        'dealname',
        'dealstage',
        'pipeline',
        'hs_is_closed',
        'hs_is_closed_won',
        'closedate',
    ];

    private const PUBLIC_EMAIL_DOMAINS = [
        'gmail.com',
        'hotmail.com',
        'outlook.com',
        'yahoo.com',
        'icloud.com',
        'live.com',
        'uol.com.br',
        'bol.com.br',
        'terra.com.br',
    ];

    public function name(): string
    {
        return 'hubspot';
    }

    public function findCompany(
        Company $company
    ): array {
        $this->assertConfigured();

        $domains =
            $this->candidateDomains(
                $company
            );

        /*
         * Estratégia 1:
         * domínio corporativo.
         */
        if ($domains !== []) {
            $results =
                $this->searchByDomains(
                    $domains
                );

            $match =
                $this->domainMatch(
                    $results,
                    $domains
                );

            if ($match !== null) {
                return $this->result(
                    $match,
                    'domain',
                    mb_strtolower(
                        trim(
                            (string) data_get(
                                $match,
                                'properties.domain'
                            )
                        )
                    ),
                    [
                        'search_strategy' => 'domain',

                        'candidate_domains' => $domains,

                        'hubspot_result_count' => count($results),
                    ],
                );
            }
        }

        /*
         * Estratégia 2:
         * nome da empresa.
         *
         * O HubSpot faz busca textual;
         * depois nós validamos o nome
         * normalizado para evitar falso positivo.
         */
        $results =
            $this->searchByName(
                $company
                    ->corporate_name
            );

        $match =
            $this->nameMatch(
                $company,
                $results
            );

        if ($match !== null) {
            return $this->result(
                $match,
                'name',
                (string) data_get(
                    $match,
                    'properties.name'
                ),
                [
                    'search_strategy' => 'name',

                    'candidate_domains' => $domains,

                    'hubspot_result_count' => count($results),
                ],
            );
        }

        return [
            'found' => false,

            'external_id' => null,

            'name' => null,

            'domain' => null,

            'lifecycle_stage' => null,

            'owner_id' => null,

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'deals' => [],

            'last_contacted_at' => null,

            'matched_by' => null,

            'matched_value' => null,

            'external_url' => null,

            'metadata' => [
                'search_strategy' => 'domain_then_name',

                'candidate_domains' => $domains,
            ],
        ];
    }

    /**
     * @param  list<string>  $domains
     * @return list<array<string, mixed>>
     */
    private function searchByDomains(
        array $domains
    ): array {
        $filterGroups = [];

        $variants = [];

        foreach ($domains as $domain) {
            $normalized =
                $this->normalizeDomain(
                    $domain
                );

            if ($normalized === null) {
                continue;
            }

            $variants[] =
                $normalized;

            $variants[] =
                'www.'.$normalized;
        }

        $variants =
            array_values(
                array_unique(
                    $variants
                )
            );

        foreach (
            array_slice(
                $variants,
                0,
                5
            ) as $domain
        ) {
            $filterGroups[] = [
                'filters' => [
                    [
                        'propertyName' => 'domain',

                        'operator' => 'EQ',

                        'value' => $domain,
                    ],
                ],
            ];
        }

        return $this->search([
            'filterGroups' => $filterGroups,

            'properties' => self::PROPERTIES,

            'limit' => 10,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchByName(
        string $name
    ): array {
        return $this->search([
            'query' => mb_substr(
                trim($name),
                0,
                200
            ),

            'properties' => self::PROPERTIES,

            'limit' => 10,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function search(
        array $payload
    ): array {
        $baseUrl = rtrim(
            (string) config(
                'services.hubspot.base_url'
            ),
            '/'
        );

        $token = trim(
            (string) config(
                'services.hubspot.access_token'
            )
        );

        try {
            $response =
                Http::withToken(
                    $token
                )
                    ->acceptJson()
                    ->asJson()
                    ->connectTimeout(5)
                    ->timeout(30)
                    ->post(
                        $baseUrl
                        .'/crm/v3/objects/companies/search',
                        $payload
                    );
        } catch (
            ConnectionException $exception
        ) {
            throw new RuntimeException(
                'Não foi possível conectar ao HubSpot.',
                previous: $exception,
            );
        }

        if ($response->status() === 401) {
            throw new RuntimeException(
                'Token do HubSpot inválido ou expirado.'
            );
        }

        if ($response->status() === 403) {
            throw new RuntimeException(
                'O token do HubSpot não possui permissão para consultar empresas.'
            );
        }

        if ($response->status() === 429) {
            throw new RuntimeException(
                'Limite de consultas do HubSpot atingido.'
            );
        }

        if ($response->serverError()) {
            throw new RuntimeException(
                'HubSpot temporariamente indisponível. HTTP '
                .$response->status()
                .'.'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Erro ao consultar HubSpot. HTTP '
                .$response->status()
                .'.'
            );
        }

        $data =
            $response->json();

        if (! is_array($data)) {
            throw new RuntimeException(
                'O HubSpot retornou uma resposta inválida.'
            );
        }

        $results =
            $data['results']
            ?? [];

        if (! is_array($results)) {
            return [];
        }

        $valid = [];

        foreach ($results as $result) {
            if (is_array($result)) {
                $valid[] = $result;
            }
        }

        return $valid;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  list<string>  $domains
     * @return array<string, mixed>|null
     */
    private function domainMatch(
        array $results,
        array $domains,
    ): ?array {
        foreach ($results as $result) {
            $domain =
                $this->normalizeDomain(
                    data_get(
                        $result,
                        'properties.domain'
                    )
                );

            $normalizedDomains =
                array_values(
                    array_filter(
                        array_map(
                            fn (string $candidate): ?string => $this->normalizeDomain(
                                $candidate
                            ),
                            $domains
                        )
                    )
                );

            if (
                $domain !== null
                && in_array(
                    $domain,
                    $normalizedDomains,
                    true
                )
            ) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array<string, mixed>|null
     */
    private function nameMatch(
        Company $company,
        array $results,
    ): ?array {
        $expected =
            $this->companyNameMatchKey(
                $company
                    ->corporate_name
            );

        foreach ($results as $result) {
            $name =
                data_get(
                    $result,
                    'properties.name'
                );

            if (! is_string($name)) {
                continue;
            }

            if (
                $this->companyNameMatchKey(
                    $name
                )
                === $expected
            ) {
                return $result;
            }
        }

        return null;
    }

    private function normalizeDomain(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value =
            mb_strtolower(
                trim(
                    $value
                )
            );

        if ($value === '') {
            return null;
        }

        /*
         * O campo domain do HubSpot às vezes
         * contém www., enquanto um domínio
         * extraído de e-mail naturalmente não.
         */
        if (
            str_starts_with(
                $value,
                'www.'
            )
        ) {
            $value =
                mb_substr(
                    $value,
                    4
                );
        }

        return rtrim(
            $value,
            '.'
        );
    }

    private function companyNameMatchKey(
        ?string $value
    ): ?string {
        $normalized =
            TextNormalizer::companyName(
                $value
            );

        if ($normalized === null) {
            return null;
        }

        /*
         * O nome cadastral da Receita costuma
         * carregar a forma societária, enquanto
         * o HubSpot muitas vezes guarda apenas
         * o nome comercial.
         *
         * Removemos somente sufixos no FINAL
         * para evitar matches excessivamente
         * permissivos.
         */
        $withoutLegalSuffix =
            preg_replace(
                '/\\s+(?:S\\s+A|SA|LTDA|EIRELI|ME|EPP)$/',
                '',
                $normalized
            );

        return trim(
            $withoutLegalSuffix
            ?? $normalized
        );
    }

    /**
     * @return list<string>
     */
    private function candidateDomains(
        Company $company
    ): array {
        $company->loadMissing(
            'establishments'
        );

        $counts = [];

        foreach (
            $company
                ->establishments as $establishment
        ) {
            /*
             * Se sabemos que a unidade
             * não está ativa, ignoramos
             * o contato cadastral dela.
             */
            $status = trim(
                (string)
                    $establishment
                        ->registration_status_code
            );

            if (
                $status !== ''
                && $status !== '02'
            ) {
                continue;
            }

            $email = mb_strtolower(
                trim(
                    (string)
                        $establishment
                            ->email
                )
            );

            if (
                $email === ''
                || filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                ) === false
            ) {
                continue;
            }

            $parts = explode(
                '@',
                $email
            );

            $domain =
                $this->normalizeDomain(
                    (string) end(
                        $parts
                    )
                );

            if (
                $domain === null
                || ! str_contains(
                    $domain,
                    '.'
                )
                || in_array(
                    $domain,
                    self::PUBLIC_EMAIL_DOMAINS,
                    true
                )
            ) {
                continue;
            }

            $counts[$domain] =
                ($counts[$domain] ?? 0)
                + 1;
        }

        arsort(
            $counts
        );

        return array_keys(
            $counts
        );
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     found: bool,
     *     external_id: string|null,
     *     name: string|null,
     *     domain: string|null,
     *     lifecycle_stage: string|null,
     *     owner_id: string|null,
     *     contacted_count: int,
     *     associated_deals_count: int,
     *     deals: list<array{
     *         id: string,
     *         name: string|null,
     *         stage_id: string|null,
     *         stage_label: string|null,
     *         pipeline_id: string|null,
     *         is_closed: bool,
     *         is_closed_won: bool,
     *         closed_at: string|null
     *     }>,
     *     last_contacted_at: string|null,
     *     matched_by: string|null,
     *     matched_value: string|null,
     *     external_url: string|null,
     *     metadata: array<string, mixed>
     * }
     */
    private function result(
        array $record,
        string $matchedBy,
        string $matchedValue,
        array $metadata,
    ): array {
        $properties =
            data_get(
                $record,
                'properties',
                []
            );

        if (! is_array($properties)) {
            $properties = [];
        }

        $id =
            isset($record['id'])
                ? (string)
                    $record['id']
                : null;

        $deals =
            $id !== null
                ? $this->companyDeals(
                    $id
                )
                : [];

        return [
            'found' => true,

            'external_id' => $id,

            'name' => $this->nullable(
                $properties[
                    'name'
                ] ?? null
            ),

            'domain' => $this->nullable(
                $properties[
                    'domain'
                ] ?? null
            ),

            'lifecycle_stage' => $this->nullable(
                $properties[
                    'lifecyclestage'
                ] ?? null
            ),

            'owner_id' => $this->nullable(
                $properties[
                    'hubspot_owner_id'
                ] ?? null
            ),

            'contacted_count' => (int) (
                $properties[
                    'num_contacted_notes'
                ] ?? 0
            ),

            'associated_deals_count' => count(
                $deals
            ),

            'deals' => $deals,

            'last_contacted_at' => $this->nullable(
                $properties[
                    'notes_last_contacted'
                ] ?? null
            ),

            'matched_by' => $matchedBy,

            'matched_value' => $matchedValue,

            'external_url' => $id !== null
                    ? $this->recordUrl(
                        $id
                    )
                    : null,

            'metadata' => $metadata,
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     name: string|null,
     *     stage_id: string|null,
     *     stage_label: string|null,
     *     pipeline_id: string|null,
     *     is_closed: bool,
     *     is_closed_won: bool,
     *     closed_at: string|null
     * }>
     */
    private function companyDeals(
        string $companyId
    ): array {
        $ids =
            $this->companyDealIds(
                $companyId
            );

        if ($ids === []) {
            return [];
        }

        $records =
            $this->readDeals(
                $ids
            );

        $stageLabels =
            $this->pipelineStageLabels();

        $deals = [];

        foreach ($records as $record) {
            $properties =
                data_get(
                    $record,
                    'properties',
                    []
                );

            if (! is_array($properties)) {
                continue;
            }

            $id =
                isset($record['id'])
                    ? (string) $record['id']
                    : null;

            if (
                $id === null
                || $id === ''
            ) {
                continue;
            }

            $stageId =
                $this->nullable(
                    $properties[
                        'dealstage'
                    ] ?? null
                );

            $pipelineId =
                $this->nullable(
                    $properties[
                        'pipeline'
                    ] ?? null
                );

            $stageKey =
                $pipelineId !== null
                && $stageId !== null
                    ? $pipelineId
                        .'|'
                        .$stageId
                    : null;

            $deals[] = [
                'id' => $id,

                'name' => $this->nullable(
                    $properties[
                        'dealname'
                    ] ?? null
                ),

                'stage_id' => $stageId,

                'stage_label' => $stageKey !== null
                        ? (
                            $stageLabels[
                                $stageKey
                            ]
                            ?? $stageId
                        )
                        : null,

                'pipeline_id' => $pipelineId,

                'is_closed' => $this->hubSpotBoolean(
                    $properties[
                        'hs_is_closed'
                    ] ?? false
                ),

                'is_closed_won' => $this->hubSpotBoolean(
                    $properties[
                        'hs_is_closed_won'
                    ] ?? false
                ),

                'closed_at' => $this->nullable(
                    $properties[
                        'closedate'
                    ] ?? null
                ),
            ];
        }

        return $deals;
    }

    /**
     * @return list<string>
     */
    private function companyDealIds(
        string $companyId
    ): array {
        $baseUrl =
            $this->baseUrl();

        $token =
            $this->token();

        $ids = [];

        $after = null;

        do {
            $url =
                $baseUrl
                .'/crm/v3/objects/companies/'
                .rawurlencode($companyId)
                .'/associations/deals';

            $query = [
                'limit' => 100,
            ];

            if ($after !== null) {
                $query['after'] =
                    $after;
            }

            try {
                $response =
                    Http::withToken(
                        $token
                    )
                        ->acceptJson()
                        ->connectTimeout(5)
                        ->timeout(30)
                        ->get(
                            $url,
                            $query
                        );
            } catch (
                ConnectionException $exception
            ) {
                throw new RuntimeException(
                    'Não foi possível consultar '
                    .'os negócios associados '
                    .'no HubSpot.',
                    previous: $exception,
                );
            }

            $this->assertHubSpotResponse(
                $response->status(),
                $response->successful(),
                'consultar negócios associados'
            );

            $data =
                $response->json();

            if (! is_array($data)) {
                throw new RuntimeException(
                    'O HubSpot retornou uma '
                    .'resposta inválida para '
                    .'associações de negócios.'
                );
            }

            $results =
                $data['results']
                ?? [];

            if (is_array($results)) {
                foreach ($results as $result) {
                    if (! is_array($result)) {
                        continue;
                    }

                    $id =
                        $result['id']
                        ?? null;

                    if (
                        is_string($id)
                        || is_int($id)
                    ) {
                        $ids[] =
                            (string) $id;
                    }
                }
            }

            $nextAfter =
                data_get(
                    $data,
                    'paging.next.after'
                );

            $after =
                is_string($nextAfter)
                || is_int($nextAfter)
                    ? (string) $nextAfter
                    : null;

        } while ($after !== null);

        return array_values(
            array_unique(
                $ids
            )
        );
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function readDeals(
        array $ids
    ): array {
        $baseUrl =
            $this->baseUrl();

        $token =
            $this->token();

        $records = [];

        /*
         * O endpoint batch possui limite,
         * então dividimos para nunca depender
         * de uma quantidade específica de
         * negócios por empresa.
         */
        foreach (
            array_chunk(
                $ids,
                100
            ) as $chunk
        ) {
            try {
                $response =
                    Http::withToken(
                        $token
                    )
                        ->acceptJson()
                        ->asJson()
                        ->connectTimeout(5)
                        ->timeout(30)
                        ->post(
                            $baseUrl
                            .'/crm/v3/objects/deals/batch/read',
                            [
                                'properties' => self::DEAL_PROPERTIES,

                                'inputs' => array_map(
                                    static fn (
                                        string $id
                                    ): array => [
                                        'id' => $id,
                                    ],
                                    $chunk
                                ),
                            ]
                        );
            } catch (
                ConnectionException $exception
            ) {
                throw new RuntimeException(
                    'Não foi possível consultar '
                    .'os negócios no HubSpot.',
                    previous: $exception,
                );
            }

            $this->assertHubSpotResponse(
                $response->status(),
                $response->successful(),
                'consultar negócios'
            );

            $data =
                $response->json();

            if (! is_array($data)) {
                throw new RuntimeException(
                    'O HubSpot retornou uma '
                    .'resposta inválida para '
                    .'os negócios.'
                );
            }

            $results =
                $data['results']
                ?? [];

            if (! is_array($results)) {
                continue;
            }

            foreach ($results as $result) {
                if (is_array($result)) {
                    $records[] =
                        $result;
                }
            }
        }

        return $records;
    }

    /**
     * @return array<string, string>
     */
    private function pipelineStageLabels(): array
    {
        $baseUrl =
            $this->baseUrl();

        $token =
            $this->token();

        try {
            $response =
                Http::withToken(
                    $token
                )
                    ->acceptJson()
                    ->connectTimeout(5)
                    ->timeout(30)
                    ->get(
                        $baseUrl
                        .'/crm/v3/pipelines/deals'
                    );
        } catch (
            ConnectionException $exception
        ) {
            /*
             * O label é informativo.
             *
             * Não deixamos a classificação
             * comercial inteira falhar apenas
             * porque o endpoint de pipelines
             * ficou indisponível.
             */
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $data =
            $response->json();

        if (! is_array($data)) {
            return [];
        }

        $pipelines =
            $data['results']
            ?? [];

        if (! is_array($pipelines)) {
            return [];
        }

        $labels = [];

        foreach ($pipelines as $pipeline) {
            if (! is_array($pipeline)) {
                continue;
            }

            $pipelineId =
                $pipeline['id']
                ?? null;

            if (
                ! is_string($pipelineId)
                && ! is_int($pipelineId)
            ) {
                continue;
            }

            $stages =
                $pipeline['stages']
                ?? [];

            if (! is_array($stages)) {
                continue;
            }

            foreach ($stages as $stage) {
                if (! is_array($stage)) {
                    continue;
                }

                $stageId =
                    $stage['id']
                    ?? null;

                $label =
                    $stage['label']
                    ?? null;

                if (
                    (
                        ! is_string($stageId)
                        && ! is_int($stageId)
                    )
                    || ! is_string($label)
                    || trim($label) === ''
                ) {
                    continue;
                }

                $labels[
                    (string) $pipelineId
                    .'|'
                    .(string) $stageId
                ] = trim($label);
            }
        }

        return $labels;
    }

    private function hubSpotBoolean(
        mixed $value
    ): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(
            mb_strtolower(
                trim($value)
            ),
            [
                'true',
                '1',
                'yes',
            ],
            true
        );
    }

    private function baseUrl(): string
    {
        return rtrim(
            (string) config(
                'services.hubspot.base_url'
            ),
            '/'
        );
    }

    private function token(): string
    {
        return trim(
            (string) config(
                'services.hubspot.access_token'
            )
        );
    }

    private function assertHubSpotResponse(
        int $status,
        bool $successful,
        string $operation,
    ): void {
        if ($status === 401) {
            throw new RuntimeException(
                'Token do HubSpot inválido '
                .'ou expirado.'
            );
        }

        if ($status === 403) {
            throw new RuntimeException(
                'O token do HubSpot não possui '
                .'permissão para '
                .$operation
                .'.'
            );
        }

        if ($status === 429) {
            throw new RuntimeException(
                'Limite de consultas do '
                .'HubSpot atingido.'
            );
        }

        if (
            $status >= 500
            && $status <= 599
        ) {
            throw new RuntimeException(
                'HubSpot temporariamente '
                .'indisponível. HTTP '
                .$status
                .'.'
            );
        }

        if (! $successful) {
            throw new RuntimeException(
                'Erro ao '
                .$operation
                .' no HubSpot. HTTP '
                .$status
                .'.'
            );
        }
    }

    private function recordUrl(
        string $id
    ): ?string {
        $portalId = trim(
            (string) config(
                'services.hubspot.portal_id'
            )
        );

        if ($portalId === '') {
            return null;
        }

        return sprintf(
            'https://app.hubspot.com/contacts/%s/record/0-2/%s',
            rawurlencode(
                $portalId
            ),
            rawurlencode(
                $id
            ),
        );
    }

    private function nullable(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim(
            (string) $value
        );

        return $value !== ''
            ? $value
            : null;
    }

    private function assertConfigured(): void
    {
        if (
            trim(
                (string) config(
                    'services.hubspot.access_token'
                )
            ) === ''
        ) {
            throw new RuntimeException(
                'HUBSPOT_ACCESS_TOKEN não configurado.'
            );
        }
    }
}
