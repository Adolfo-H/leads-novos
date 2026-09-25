<?php

namespace App\Services;

use App\Models\HubSpotCompany;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HubSpotWebhookAssociationResolver
{
    public function __construct(
        private readonly HubSpotWebhookObjectTypeService $types,
    ) {}

    /**
     * Resolve um objeto HubSpot para uma ou mais
     * empresas fiscais do Prospector.
     *
     * @return list<int>
     */
    public function companyIds(
        string $objectType,
        string $objectId,
    ): array {
        /*
         * Primeiro tentamos somente dados locais.
         *
         * Além das projeções operacionais,
         * consultamos o espelho completo do HubSpot.
         */
        $ids =
            match ($objectType) {
                'company' => $this->localByCompany(
                    $objectId
                ),

                'deal' => $this->localByDeal(
                    $objectId
                ),

                'contact' => $this->localByContact(
                    $objectId
                ),

                default => [],
            };

        $ids =
            $this->unique(
                $ids
            );

        /*
         * Deal múltiplo sem Primary não pode
         * cair em associação genérica.
         *
         * Retornar vazio é mais seguro do que
         * atribuir o negócio à empresa errada.
         */
        if (
            $objectType === 'deal'
        ) {
            return $ids;
        }

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
         * 1. Associação direta com Company.
         *
         * Exemplo:
         * Note -> Company
         * Call -> Company
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

        $ids =
            $this->unique(
                $ids
            );

        /*
         * Se a atividade já aponta diretamente
         * para uma Company conhecida, não fazemos
         * consultas desnecessárias.
         */
        if ($ids !== []) {
            return $ids;
        }

        /*
         * 2. Associação via Deal.
         *
         * Atividades podem estar vinculadas
         * somente a um negócio.
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

        $ids =
            $this->unique(
                $ids
            );

        if ($ids !== []) {
            return $ids;
        }

        /*
         * 3. Associação via Contact.
         *
         * Algumas ligações, notas, e-mails e
         * reuniões podem estar ligadas somente
         * ao contato.
         */
        $contactExternalIds =
            $this->associationIds(
                fromType: $fromType,

                fromId: $objectId,

                toType: 'contacts',
            );

        foreach (
            $contactExternalIds as $contactExternalId
        ) {
            $ids = array_merge(
                $ids,

                $this->localByContact(
                    $contactExternalId
                )
            );
        }

        return $this->unique(
            $ids
        );
    }

    /**
     * Resolve IDs externos de Companies do
     * HubSpot sem exigir vínculo fiscal.
     *
     * Isto permite preservar atividades mesmo
     * quando hubspot_companies.company_id ainda
     * é NULL.
     *
     * @return list<string>
     */
    public function hubSpotCompanyIds(
        string $objectType,
        string $objectId,
    ): array {
        $ids = [];

        if ($objectType === 'company') {
            $ids[] =
                $objectId;
        }

        $fromType =
            $this
                ->types
                ->apiPlural(
                    $objectType
                );

        if ($fromType === null) {
            return $this->uniqueStrings(
                $ids
            );
        }

        /*
         * Associação direta.
         *
         * Note -> Company
         * Call -> Company
         * Task -> Company
         * etc.
         */
        if ($objectType !== 'company') {
            $ids =
                array_merge(
                    $ids,

                    $this->associationIds(
                        fromType: $fromType,

                        fromId: $objectId,

                        toType: 'companies',
                    )
                );
        }

        /*
         * Via negócio.
         */
        $dealIds =
            $objectType === 'deal'
                ? [
                    $objectId,
                ]
                : $this->associationIds(
                    fromType: $fromType,

                    fromId: $objectId,

                    toType: 'deals',
                );

        foreach ($dealIds as $dealId) {
            $ids =
                array_merge(
                    $ids,

                    $this->associationIds(
                        fromType: 'deals',

                        fromId: $dealId,

                        toType: 'companies',
                    )
                );
        }

        /*
         * Via contato.
         *
         * Algumas atividades não são ligadas
         * diretamente à Company, apenas ao
         * Contact.
         */
        $contactIds =
            $objectType === 'contact'
                ? [
                    $objectId,
                ]
                : $this->associationIds(
                    fromType: $fromType,

                    fromId: $objectId,

                    toType: 'contacts',
                );

        foreach (
            $contactIds as $contactId
        ) {
            $ids =
                array_merge(
                    $ids,

                    $this->associationIds(
                        fromType: 'contacts',

                        fromId: $contactId,

                        toType: 'companies',
                    )
                );

            /*
             * Contact -> Deal -> Company.
             */
            $contactDealIds =
                $this->associationIds(
                    fromType: 'contacts',

                    fromId: $contactId,

                    toType: 'deals',
                );

            foreach (
                $contactDealIds as $dealId
            ) {
                $ids =
                    array_merge(
                        $ids,

                        $this->associationIds(
                            fromType: 'deals',

                            fromId: $dealId,

                            toType: 'companies',
                        )
                    );
            }
        }

        return $this->uniqueStrings(
            $ids
        );
    }

    /**
     * Resolve HubSpot Company ID.
     *
     * Fontes:
     * - projeção de leads;
     * - CRM Check;
     * - espelho HubSpot reconstruído.
     *
     * @return list<int>
     */
    /**
     * HubSpot Company -> Company fiscal.
     *
     * A única prova local aceita aqui é o
     * vínculo fiscal confiável do mirror.
     *
     * @return list<int>
     */
    private function localByCompany(
        string $hubSpotCompanyId
    ): array {
        return array_values(
            HubSpotCompany::query()
                ->trustedFiscalLink()
                ->where(
                    'hubspot_id',
                    $hubSpotCompanyId
                )
                ->whereNotNull(
                    'company_id'
                )
                ->pluck(
                    'company_id'
                )
                ->map(
                    static fn (
                        mixed $id
                    ): int => (int) $id
                )
                ->filter(
                    static fn (
                        int $id
                    ): bool => $id > 0
                )
                ->unique()
                ->values()
                ->all()
        );
    }

    /**
     * Deal -> HubSpot Company ->
     * Company fiscal confiável.
     *
     * O Deal pode estar associado a várias
     * Companies no HubSpot. Isso NÃO transfere
     * CNPJ entre elas.
     *
     * @return list<int>
     */
    private function localByDeal(
        string $hubSpotDealId
    ): array {
        return array_values(
            DB::table(
                'hubspot_deals as hd'
            )
                ->join(
                    'hubspot_company_deal as hcd',
                    'hcd.hubspot_deal_id',
                    '=',
                    'hd.id'
                )
                ->join(
                    'hubspot_companies as hc',
                    'hc.id',
                    '=',
                    'hcd.hubspot_company_id'
                )
                ->where(
                    'hd.hubspot_id',
                    $hubSpotDealId
                )
                ->whereNotNull(
                    'hc.company_id'
                )
                ->where(
                    function (
                        $query
                    ): void {
                        $query
                            ->whereNull(
                                'hc.match_source'
                            )
                            ->orWhereNotIn(
                                'hc.match_source',
                                HubSpotCompany::UNSAFE_FISCAL_MATCH_SOURCES
                            );
                    }
                )
                ->where(
                    function (
                        $query
                    ): void {
                        $query
                            ->where(
                                'hcd.is_primary',
                                true
                            )
                            ->orWhereRaw(
                                '(SELECT COUNT(*) '
                                .'FROM hubspot_company_deal hcd_count '
                                .'WHERE hcd_count.hubspot_deal_id = '
                                .'hcd.hubspot_deal_id) = 1'
                            );
                    }
                )
                ->pluck(
                    'hc.company_id'
                )
                ->map(
                    static fn (
                        mixed $id
                    ): int => (int) $id
                )
                ->filter(
                    static fn (
                        int $id
                    ): bool => $id > 0
                )
                ->unique()
                ->values()
                ->all()
        );
    }

    /**
     * Contact -> HubSpot Company ->
     * Company fiscal confiável.
     *
     * @return list<int>
     */
    private function localByContact(
        string $hubSpotContactId
    ): array {
        return array_values(
            DB::table(
                'hubspot_contacts as hct'
            )
                ->join(
                    'hubspot_company_contact as hcc',
                    'hcc.hubspot_contact_id',
                    '=',
                    'hct.id'
                )
                ->join(
                    'hubspot_companies as hc',
                    'hc.id',
                    '=',
                    'hcc.hubspot_company_id'
                )
                ->where(
                    'hct.hubspot_id',
                    $hubSpotContactId
                )
                ->whereNotNull(
                    'hc.company_id'
                )
                ->where(
                    function (
                        $query
                    ): void {
                        $query
                            ->whereNull(
                                'hc.match_source'
                            )
                            ->orWhereNotIn(
                                'hc.match_source',
                                HubSpotCompany::UNSAFE_FISCAL_MATCH_SOURCES
                            );
                    }
                )
                ->pluck(
                    'hc.company_id'
                )
                ->map(
                    static fn (
                        mixed $id
                    ): int => (int) $id
                )
                ->filter(
                    static fn (
                        int $id
                    ): bool => $id > 0
                )
                ->unique()
                ->values()
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
         * Objeto removido ou associação
         * inexistente.
         */
        if (
            $response->status()
            === 404
        ) {
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

        foreach (
            $results as $result
        ) {
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
        if (
            $response->status()
            === 401
        ) {
            throw new RuntimeException(
                'Token do HubSpot inválido ou expirado.'
            );
        }

        if (
            $response->status()
            === 403
        ) {
            throw new RuntimeException(
                'Token sem permissão para consultar associações do HubSpot.'
            );
        }

        if (
            $response->status()
            === 429
        ) {
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
     * @param  array<int, string>  $ids
     * @return list<string>
     */
    private function uniqueStrings(
        array $ids
    ): array {
        $result = [];

        foreach ($ids as $id) {
            $id =
                trim(
                    $id
                );

            if ($id !== '') {
                $result[] =
                    $id;
            }
        }

        return array_values(
            array_unique(
                $result
            )
        );
    }

    /**
     * @param  array<int>  $ids
     * @return list<int>
     */
    private function unique(
        array $ids
    ): array {
        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn (
                            mixed $id
                        ): int => (int) $id,
                        $ids
                    ),
                    static fn (
                        int $id
                    ): bool => $id > 0
                )
            )
        );
    }
}
