<?php

namespace App\Services;

use App\Contracts\CrmCompanyProvider;
use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class HubSpotLeadSyncService
{
    /**
     * @var list<string>
     */
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

    public function __construct(
        private readonly HubSpotLeadEligibilityService $eligibility,
        private readonly CrmCompanyProvider $crmProvider,
    ) {}

    public function sync(
        Company $company
    ): CompanyHubSpotLead {
        $lock =
            Cache::lock(
                'hubspot-lead-sync-company-'
                .$company->id,
                240
            );

        if (! $lock->get()) {
            throw new RuntimeException(
                'Sincronização HubSpot desta empresa já está em andamento.'
            );
        }

        try {
            return $this->syncWithoutLock(
                $company
            );
        } finally {
            $lock->release();
        }
    }

    private function syncWithoutLock(
        Company $company
    ): CompanyHubSpotLead {
        $company->loadMissing([
            'establishments',
            'matrix',
            'sdrScore',
            'crmCheck',
            'hubSpotLead',
        ]);

        $eligibility =
            $this->eligibility->evaluate(
                $company
            );

        if (! $eligibility['eligible']) {
            throw new RuntimeException(
                $eligibility['reason']
            );
        }

        $existingSync =
            $company->hubSpotLead;

        /*
         * Na PRIMEIRA tentativa continuamos
         * conservadores.
         *
         * Se a empresa apareceu no HubSpot
         * antes de criarmos qualquer estado
         * local de sincronização, cancelamos.
         *
         * Isso evita assumir como nosso um
         * registro criado manualmente.
         */
        if ($existingSync === null) {
            $freshCrm =
                $this->crmProvider
                    ->findCompany(
                        $company
                    );

            if ($freshCrm['found']) {
                throw new RuntimeException(
                    'A empresa passou a existir no HubSpot. '
                    .'Sincronização automática cancelada.'
                );
            }
        }

        $sync =
            CompanyHubSpotLead::query()
                ->firstOrCreate(
                    [
                        'company_id' => $company->id,
                    ],
                    [
                        'pipeline_id' => $this->pipelineId(),

                        'deal_stage_id' => $this->initialStageId(),

                        'metadata' => [],
                    ]
                );

        try {
            /*
             * Se já existe uma linha local mas a
             * sincronização não terminou, esta é
             * uma retomada.
             *
             * Consultamos novamente o HubSpot
             * para recuperar IDs que possam ter
             * sido criados remotamente antes de
             * uma queda de conexão/processo.
             */
            if ($existingSync !== null) {
                $freshCrm =
                    $this->crmProvider
                        ->findCompany(
                            $company
                        );

                if ($freshCrm['found']) {
                    $this->reconcilePartialSync(
                        sync: $sync,
                        company: $company,
                        freshCrm: $freshCrm,
                    );
                }
            }

            if (
                $sync->hubspot_company_id
                === null
            ) {
                $sync->hubspot_company_id =
                    $this->createCompany(
                        $company
                    );

                $sync->save();
            }

            $contact =
                $this->contactData(
                    $company
                );

            if (
                $sync->hubspot_contact_id
                    === null
                && $contact !== null
            ) {
                $sync->hubspot_contact_id =
                    $this->resolveContact(
                        company: $company,
                        email: $contact['email'],
                        phone: $contact['phone'],
                    );

                $sync->save();
            }

            if (
                $sync->hubspot_contact_id
                !== null
            ) {
                $this->associate(
                    fromType: 'companies',
                    fromId: $sync->hubspot_company_id,
                    toType: 'contacts',
                    toId: $sync->hubspot_contact_id,
                );
            }

            if (
                $sync->hubspot_deal_id
                === null
            ) {
                $sync->hubspot_deal_id =
                    $this->createDeal(
                        $company
                    );

                $sync->save();
            }

            $this->associate(
                fromType: 'companies',
                fromId: $sync->hubspot_company_id,
                toType: 'deals',
                toId: $sync->hubspot_deal_id,
            );

            if (
                $sync->hubspot_contact_id
                !== null
            ) {
                $this->associate(
                    fromType: 'contacts',
                    fromId: $sync->hubspot_contact_id,
                    toType: 'deals',
                    toId: $sync->hubspot_deal_id,
                );
            }

            $rawMetadata =
                $sync->getAttribute(
                    'metadata'
                );

            /** @var mixed $rawMetadata */
            $metadata =
                is_array(
                    $rawMetadata
                )
                    ? $rawMetadata
                    : [];

            $metadata[
                'contact_email'
            ] =
                $contact['email']
                ?? null;

            $metadata[
                'contact_created'
            ] =
                $sync->hubspot_contact_id
                !== null;

            /*
             * Preserva o contexto que qualificou
             * o lead antes de ele virar uma
             * oportunidade no HubSpot.
             *
             * Depois da criação do negócio o CRM
             * passa a reportar "opportunity" e o
             * SDR pode ficar bloqueado/zerado.
             *
             * Isso é correto para impedir nova
             * prospecção, mas não deve apagar a
             * qualificação comercial original.
             */
            $scoreSnapshot =
                $company->sdrScore;

            $crmSnapshot =
                $company->crmCheck;

            if (
                $scoreSnapshot !== null
                && $crmSnapshot !== null
            ) {
                $metadata[
                    'qualification_snapshot'
                ] = [
                    'score' => (int) $scoreSnapshot->score,

                    'priority' => $scoreSnapshot->priority,

                    'label' => $scoreSnapshot->label,

                    'crm_status' => $crmSnapshot->status,

                    'captured_at' => now()->toIso8601String(),
                ];
            }

            $sync->forceFill([
                'pipeline_id' => $this->pipelineId(),

                'deal_stage_id' => $this->initialStageId(),

                'synced_at' => now(),

                'sync_error' => null,

                'metadata' => $metadata,
            ])->save();

            return $sync->refresh();
        } catch (Throwable $exception) {
            $sync->forceFill([
                'sync_error' => mb_substr(
                    $exception->getMessage(),
                    0,
                    2000
                ),
            ])->save();

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $freshCrm
     */
    private function reconcilePartialSync(
        CompanyHubSpotLead $sync,
        Company $company,
        array $freshCrm,
    ): void {
        $externalId =
            $freshCrm[
                'external_id'
            ]
            ?? null;

        if (
            ! is_scalar(
                $externalId
            )
            || trim(
                (string) $externalId
            ) === ''
        ) {
            throw new RuntimeException(
                'HubSpot encontrou a empresa, mas não retornou um ID válido.'
            );
        }

        $externalId =
            trim(
                (string) $externalId
            );

        /*
         * Se já salvamos um company ID local,
         * nunca aceitamos silenciosamente um
         * registro remoto diferente.
         */
        if (
            $sync->hubspot_company_id !== null
            && $sync->hubspot_company_id
                !== $externalId
        ) {
            throw new RuntimeException(
                'A empresa encontrada no HubSpot não corresponde '
                .'ao registro da sincronização em andamento.'
            );
        }

        if (
            $sync->hubspot_company_id
            === null
        ) {
            $sync->hubspot_company_id =
                $externalId;
        }

        /*
         * O contato já possui recuperação por
         * e-mail dentro de resolveContact().
         *
         * Aqui recuperamos especialmente o Deal
         * criado antes de uma resposta perdida.
         */
        if (
            $sync->hubspot_deal_id
            === null
        ) {
            $deals =
                $freshCrm[
                    'deals'
                ]
                ?? [];

            $matches = [];

            if (is_array($deals)) {
                foreach ($deals as $deal) {
                    if (! is_array($deal)) {
                        continue;
                    }

                    $id =
                        $deal[
                            'id'
                        ]
                        ?? null;

                    $name =
                        $deal[
                            'name'
                        ]
                        ?? null;

                    $pipeline =
                        $deal[
                            'pipeline_id'
                        ]
                        ?? null;

                    if (
                        ! is_scalar($id)
                        || ! is_scalar($name)
                        || ! is_scalar($pipeline)
                    ) {
                        continue;
                    }

                    if (
                        (string) $name
                            !== $this->dealName(
                                $company
                            )
                        || (string) $pipeline
                            !== $this->pipelineId()
                    ) {
                        continue;
                    }

                    $matches[] = $deal;
                }
            }

            if (count($matches) > 1) {
                throw new RuntimeException(
                    'Mais de um negócio compatível foi encontrado no HubSpot. '
                    .'Sincronização automática interrompida para evitar duplicidade.'
                );
            }

            if (count($matches) === 1) {
                $match =
                    $matches[0];

                $sync->hubspot_deal_id =
                    (string) $match[
                        'id'
                    ];

                $stageId =
                    $match[
                        'stage_id'
                    ]
                    ?? null;

                if (
                    is_scalar($stageId)
                    && trim(
                        (string) $stageId
                    ) !== ''
                ) {
                    $sync->deal_stage_id =
                        (string) $stageId;
                }
            }
        }

        $sync->save();
    }

    private function dealName(
        Company $company
    ): string {
        return 'Prospecção - '
            .$company->corporate_name;
    }

    private function createCompany(
        Company $company
    ): string {
        $matrix =
            $company->matrix
            ?? $company
                ->establishments
                ->first();

        $contact =
            $this->contactData(
                $company
            );

        $properties = [
            'name' => $company->corporate_name,

            'country' => 'Brasil',
        ];

        $domain =
            $contact !== null
                ? $this->domainFromEmail(
                    $contact['email']
                )
                : null;

        if ($domain !== null) {
            $properties['domain'] =
                $domain;
        }

        $phone =
            trim(
                (string) (
                    $matrix->phone_1
                    ?? ''
                )
            );

        if ($phone !== '') {
            $properties['phone'] =
                $phone;
        }

        $city =
            trim(
                (string) (
                    $matrix->municipality_name
                    ?? ''
                )
            );

        if ($city !== '') {
            $properties['city'] =
                $city;
        }

        $state =
            trim(
                (string) (
                    $matrix->state
                    ?? ''
                )
            );

        if ($state !== '') {
            $properties['state'] =
                $state;
        }

        return $this->createObject(
            type: 'companies',
            properties: $properties,
        );
    }

    private function createDeal(
        Company $company
    ): string {
        $properties = [
            'dealname' => $this->dealName(
                $company
            ),

            'pipeline' => $this->pipelineId(),

            'dealstage' => $this->initialStageId(),

            'description' => 'Lead criado automaticamente pelo '
                .'ExportControl Prospector. '
                .'Score SDR: '
                .(
                    $company
                        ->sdrScore
                        ->score
                    ?? 0
                )
                .'/100.',
        ];

        return $this->createObject(
            type: 'deals',
            properties: $properties,
        );
    }

    private function resolveContact(
        Company $company,
        string $email,
        ?string $phone,
    ): string {
        $existing =
            $this->findContactByEmail(
                $email
            );

        if ($existing !== null) {
            return $existing;
        }

        $properties = [
            'email' => $email,

            'company' => $company->corporate_name,
        ];

        if (
            $phone !== null
            && trim($phone) !== ''
        ) {
            $properties['phone'] =
                $phone;
        }

        return $this->createObject(
            type: 'contacts',
            properties: $properties,
        );
    }

    private function findContactByEmail(
        string $email
    ): ?string {
        $response =
            $this->client()
                ->post(
                    $this->baseUrl()
                    .'/crm/v3/objects/contacts/search',
                    [
                        'filterGroups' => [
                            [
                                'filters' => [
                                    [
                                        'propertyName' => 'email',

                                        'operator' => 'EQ',

                                        'value' => $email,
                                    ],
                                ],
                            ],
                        ],

                        'properties' => [
                            'email',
                        ],

                        'limit' => 1,
                    ]
                );

        $this->ensureSuccess(
            $response,
            'consultar contato'
        );

        $data =
            $response->json();

        if (! is_array($data)) {
            return null;
        }

        $results =
            $data['results']
            ?? [];

        if (
            ! is_array($results)
            || $results === []
        ) {
            return null;
        }

        $first =
            $results[0]
            ?? null;

        if (! is_array($first)) {
            return null;
        }

        $id =
            $first['id']
            ?? null;

        return is_scalar($id)
            ? (string) $id
            : null;
    }

    /**
     * @param  array<string, string>  $properties
     */
    private function createObject(
        string $type,
        array $properties,
    ): string {
        $response =
            $this->client()
                ->post(
                    $this->baseUrl()
                    .'/crm/v3/objects/'
                    .$type,
                    [
                        'properties' => $properties,
                    ]
                );

        $this->ensureSuccess(
            $response,
            'criar '.$type
        );

        $data =
            $response->json();

        if (! is_array($data)) {
            throw new RuntimeException(
                'O HubSpot retornou resposta inválida ao criar '
                .$type
                .'.'
            );
        }

        $id =
            $data['id']
            ?? null;

        if (! is_scalar($id)) {
            throw new RuntimeException(
                'O HubSpot não retornou o ID de '
                .$type
                .'.'
            );
        }

        return (string) $id;
    }

    private function associate(
        string $fromType,
        string $fromId,
        string $toType,
        string $toId,
    ): void {
        $response =
            $this->client()
                ->put(
                    $this->baseUrl()
                    .'/crm/v4/objects/'
                    .$fromType
                    .'/'
                    .rawurlencode($fromId)
                    .'/associations/default/'
                    .$toType
                    .'/'
                    .rawurlencode($toId)
                );

        $this->ensureSuccess(
            $response,
            'associar '
            .$fromType
            .' com '
            .$toType
        );
    }

    /**
     * @return array{
     *     email: string,
     *     phone: string|null
     * }|null
     */
    private function contactData(
        Company $company
    ): ?array {
        $establishments =
            $company
                ->establishments
                ->sortByDesc(
                    function ($establishment): int {
                        $score = 0;

                        if (
                            $establishment->type
                            === 'matrix'
                        ) {
                            $score += 10;
                        }

                        if (
                            $establishment
                                ->registration_status_code
                            === '02'
                        ) {
                            $score += 5;
                        }

                        return $score;
                    }
                );

        foreach (
            $establishments as $establishment
        ) {
            $email =
                mb_strtolower(
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

            $phone =
                trim(
                    (string)
                        $establishment
                            ->phone_1
                );

            return [
                'email' => $email,

                'phone' => $phone !== ''
                        ? $phone
                        : null,
            ];
        }

        return null;
    }

    private function domainFromEmail(
        string $email
    ): ?string {
        $parts =
            explode(
                '@',
                mb_strtolower(
                    trim($email)
                )
            );

        $domain =
            trim(
                (string) end(
                    $parts
                )
            );

        if (
            $domain === ''
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
            return null;
        }

        return $domain;
    }

    private function pipelineId(): string
    {
        return trim(
            (string) config(
                'services.hubspot.lead_pipeline',
                'default'
            )
        );
    }

    private function initialStageId(): string
    {
        return trim(
            (string) config(
                'services.hubspot.lead_initial_stage',
                'appointmentscheduled'
            )
        );
    }

    private function baseUrl(): string
    {
        $baseUrl =
            rtrim(
                (string) config(
                    'services.hubspot.base_url'
                ),
                '/'
            );

        if ($baseUrl === '') {
            throw new RuntimeException(
                'HUBSPOT_BASE_URL não configurada.'
            );
        }

        return $baseUrl;
    }

    private function client(): PendingRequest
    {
        $token =
            trim(
                (string) config(
                    'services.hubspot.access_token'
                )
            );

        if ($token === '') {
            throw new RuntimeException(
                'Token do HubSpot não configurado.'
            );
        }

        return Http::withToken(
            $token
        )
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(30);
    }

    private function ensureSuccess(
        Response $response,
        string $operation,
    ): void {
        if (! $response->failed()) {
            return;
        }

        throw new RuntimeException(
            'Erro ao '
            .$operation
            .' no HubSpot. HTTP '
            .$response->status()
            .': '
            .mb_substr(
                $response->body(),
                0,
                1000
            )
        );
    }
}
