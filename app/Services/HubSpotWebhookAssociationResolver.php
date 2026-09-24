<?php

namespace App\Services;

use App\Models\CompanyCrmCheck;
use App\Models\CompanyHubSpotLead;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HubSpotWebhookAssociationResolver
{
    public function __construct(
        private readonly HubSpotWebhookObjectTypeService $types,
    ) {}

    /**
     * @return list<int>
     */
    public function companyIds(
        string $objectType,
        string $objectId,
    ): array {
        $ids = [];

        if (
            $objectType === 'company'
        ) {
            $ids = array_merge(
                $ids,
                $this->localByCompany(
                    $objectId
                )
            );
        }

        if (
            $objectType === 'deal'
        ) {
            $ids = array_merge(
                $ids,
                $this->localByDeal(
                    $objectId
                )
            );
        }

        if (
            $objectType === 'contact'
        ) {
            $ids = array_merge(
                $ids,
                CompanyHubSpotLead::query()
                    ->where(
                        'hubspot_contact_id',
                        $objectId
                    )
                    ->pluck(
                        'company_id'
                    )
                    ->map(
                        static fn (
                            mixed $id
                        ): int => (int) $id
                    )
                    ->all()
            );
        }

        /*
         * Se já encontramos localmente,
         * evitamos chamadas extras à API.
         */
        $ids =
            $this->unique(
                $ids
            );

        if ($ids !== []) {
            return $ids;
        }

        $fromType =
            $this
                ->types
                ->apiPlural(
                    $objectType
                );

        if ($fromType === null) {
            return [];
        }

        /*
         * Atividades, contatos e negócios
         * podem estar associados diretamente
         * a Company.
         */
        $companyExternalIds =
            $this->associationIds(
                fromType: $fromType,

                fromId: $objectId,

                toType: 'companies',
            );

        foreach (
            $companyExternalIds as $companyExternalId
        ) {
            $ids = array_merge(
                $ids,
                $this->localByCompany(
                    $companyExternalId
                )
            );
        }

        /*
         * Também procuramos via Deal.
         * É comum chamada/nota/tarefa estar
         * associada ao negócio, não diretamente
         * à empresa.
         */
        $dealExternalIds =
            $this->associationIds(
                fromType: $fromType,

                fromId: $objectId,

                toType: 'deals',
            );

        foreach (
            $dealExternalIds as $dealExternalId
        ) {
            $ids = array_merge(
                $ids,
                $this->localByDeal(
                    $dealExternalId
                )
            );
        }

        return $this->unique(
            $ids
        );
    }

    /**
     * @return list<int>
     */
    private function localByCompany(
        string $hubSpotCompanyId
    ): array {
        $leadIds =
            CompanyHubSpotLead::query()
                ->where(
                    'hubspot_company_id',
                    $hubSpotCompanyId
                )
                ->pluck(
                    'company_id'
                )
                ->map(
                    static fn (
                        mixed $id
                    ): int => (int) $id
                )
                ->all();

        $crmIds =
            CompanyCrmCheck::query()
                ->where(
                    'provider',
                    'hubspot'
                )
                ->where(
                    'external_id',
                    $hubSpotCompanyId
                )
                ->pluck(
                    'company_id'
                )
                ->map(
                    static fn (
                        mixed $id
                    ): int => (int) $id
                )
                ->all();

        return $this->unique(
            array_merge(
                $leadIds,
                $crmIds
            )
        );
    }

    /**
     * @return list<int>
     */
    private function localByDeal(
        string $hubSpotDealId
    ): array {
        return $this->unique(
            CompanyHubSpotLead::query()
                ->where(
                    'hubspot_deal_id',
                    $hubSpotDealId
                )
                ->pluck(
                    'company_id'
                )
                ->map(
                    static fn (
                        mixed $id
                    ): int => (int) $id
                )
                ->all()
        );
    }

    /**
     * @return list<string>
     */
    private function associationIds(
        string $fromType,
        string $fromId,
        string $toType,
    ): array {
        try {
            $response =
                $this
                    ->client()
                    ->get(
                        $this->baseUrl()
                        .'/crm/v4/objects/'
                        .rawurlencode(
                            $fromType
                        )
                        .'/'
                        .rawurlencode(
                            $fromId
                        )
                        .'/associations/'
                        .rawurlencode(
                            $toType
                        ),
                        [
                            'limit' => 100,
                        ]
                    );
        } catch (
            ConnectionException $exception
        ) {
            throw new RuntimeException(
                'Não foi possível consultar associações no HubSpot.',
                previous: $exception,
            );
        }

        /*
         * Um objeto excluído pode deixar
         * de estar disponível imediatamente.
         */
        if ($response->status() === 404) {
            return [];
        }

        $this->ensureSuccess(
            $response
        );

        $data =
            $response->json();

        if (! is_array($data)) {
            return [];
        }

        $results =
            $data[
                'results'
            ]
            ?? [];

        if (! is_array($results)) {
            return [];
        }

        $ids = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $id =
                $result[
                    'toObjectId'
                ]
                ?? $result[
                    'id'
                ]
                ?? null;

            if (! is_scalar($id)) {
                continue;
            }

            $value =
                trim(
                    (string) $id
                );

            if ($value !== '') {
                $ids[] =
                    $value;
            }
        }

        return array_values(
            array_unique(
                $ids
            )
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
            ->connectTimeout(5)
            ->timeout(30);
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

    private function ensureSuccess(
        Response $response
    ): void {
        if ($response->status() === 401) {
            throw new RuntimeException(
                'Token do HubSpot inválido ou expirado.'
            );
        }

        if ($response->status() === 403) {
            throw new RuntimeException(
                'Token sem permissão para consultar associações do HubSpot.'
            );
        }

        if ($response->status() === 429) {
            throw new RuntimeException(
                'Limite de API do HubSpot atingido.'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Falha ao consultar associações do HubSpot. HTTP '
                .$response->status()
                .'.'
            );
        }
    }

    /**
     * A entrada pode possuir índices não sequenciais
     * após merges/plucks. O retorno é normalizado
     * com array_values(), portanto é sempre list<int>.
     *
     * @param  array<int>  $ids
     * @return list<int>
     */
    private function unique(
        array $ids
    ): array {
        return array_values(
            array_unique(
                array_filter(
                    $ids,
                    static fn (
                        int $id
                    ): bool => $id > 0
                )
            )
        );
    }
}
