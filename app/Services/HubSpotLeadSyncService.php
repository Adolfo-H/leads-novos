<?php

namespace App\Services;

use App\Contracts\CrmCompanyProvider;
use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        private readonly CommercialActivityRecorder $activityRecorder,
        private readonly HubSpotOwnerResolverService $ownerResolver,
        private readonly HubSpotCompanyContactSyncService $contactSync,
    ) {}

    /**
     * Fluxo automático atual.
     *
     * Continua respeitando:
     *
     * - SDR;
     * - score mínimo;
     * - estado do CRM;
     * - demais regras automáticas.
     */
    public function sync(
        Company $company
    ): CompanyHubSpotLead {
        return $this->syncWithMode(
            company: $company,
            actor: null,
            manual: false,
        );
    }

    /**
     * Fluxo iniciado manualmente no dossiê.
     *
     * A decisão comercial foi tomada pelo
     * usuário, portanto não aplicamos os
     * bloqueios de score SDR da automação.
     */
    public function syncManual(
        Company $company,
        User $actor,
    ): CompanyHubSpotLead {
        return $this->syncWithMode(
            company: $company,
            actor: $actor,
            manual: true,
        );
    }

    private function syncWithMode(
        Company $company,
        ?User $actor,
        bool $manual,
    ): CompanyHubSpotLead {
        $lock =
            Cache::lock(
                'hubspot-lead-sync-company-'
                .$company->id,
                240
            );

        if (! $lock->get()) {
            throw new RuntimeException(
                'Sincronização HubSpot desta empresa '
                .'já está em andamento.'
            );
        }

        try {
            return $this->syncWithoutLock(
                company: $company,
                actor: $actor,
                manual: $manual,
            );
        } finally {
            $lock->release();
        }
    }

    private function syncWithoutLock(
        Company $company,
        ?User $actor,
        bool $manual,
    ): CompanyHubSpotLead {
        $company->loadMissing([
            'establishments',
            'matrix',
            'sdrScore',
            'crmCheck',
            'hubSpotLead',
        ]);

        $eligibility =
            $manual
                ? $this->eligibility
                    ->evaluateManual(
                        $company
                    )
                : $this->eligibility
                    ->evaluate(
                        $company
                    );

        if (! $eligibility['eligible']) {
            throw new RuntimeException(
                $eligibility['reason']
            );
        }

        $ownerId = null;

        if ($manual) {
            if ($actor === null) {
                throw new RuntimeException(
                    'Usuário responsável não informado '
                    .'para a criação manual.'
                );
            }

            $ownerId =
                $this->ownerResolver
                    ->resolve(
                        $actor
                    );
        }

        $existingSync =
            $company->hubSpotLead;

        $wasSynced =
            $existingSync?->synced_at
            !== null;

        /*
         * Antes da PRIMEIRA criação fazemos
         * uma nova consulta ao HubSpot.
         *
         * Isso protege a janela:
         *
         * 10:00 - Prospector diz "não existe"
         * 10:05 - alguém cria manualmente
         * 10:10 - usuário clica no Prospector
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
                    .'Criação cancelada para evitar duplicidade.'
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

        if ($manual) {
            $this->updateManualProgress(
                sync: $sync,
                progress: 15,
                step: 'owner',
                message: 'Responsável do HubSpot validado.',
            );
        }

        try {
            /*
             * Retomada depois de falha parcial.
             *
             * Se a empresa ou negócio foram
             * criados remotamente antes da queda,
             * recuperamos os IDs.
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

            /*
             * EMPRESA
             */
            if (
                $sync->hubspot_company_id
                === null
            ) {
                $sync->hubspot_company_id =
                    $this->createCompany(
                        company: $company,
                        ownerId: $ownerId,
                    );

                $sync->save();

            } elseif (
                $manual
            ) {
                /*
                 * Se uma tentativa anterior criou
                 * a empresa, garantimos o owner
                 * na retomada.
                 */
                $this->updateObject(
                    type: 'companies',
                    id: $sync->hubspot_company_id,
                    properties: [
                        'hubspot_owner_id' => $ownerId,
                    ],
                );
            }

            if ($manual) {
                $this->updateManualProgress(
                    sync: $sync,
                    progress: 30,
                    step: 'company',
                    message: 'Empresa criada e vinculada no HubSpot.',
                );
            }

            /*
             * CONTATOS
             */
            $contacts = [];

            if ($manual) {
                $this->updateManualProgress(
                    sync: $sync,
                    progress: 35,
                    step: 'contacts',
                    message: 'Sincronizando todos os contatos cadastrais...',
                );

                $contacts =
                    $this->contactSync
                        ->sync(
                            company: $company,

                            hubSpotCompanyId: $sync
                                ->hubspot_company_id,

                            ownerId: $ownerId,

                            onProgress: function (
                                int $processed,
                                int $total,
                            ) use (
                                $sync
                            ): void {
                                $progress =
                                    $total > 0
                                        ? 35
                                            + (int) floor(
                                                (
                                                    $processed
                                                    / $total
                                                )
                                                * 15
                                            )
                                        : 50;

                                $this
                                    ->updateManualProgress(
                                        sync: $sync,

                                        progress: min(
                                            50,
                                            $progress
                                        ),

                                        step: 'contacts',

                                        message: 'Sincronizando contatos: '
                                            .$processed
                                            .'/'
                                            .$total
                                            .'.',

                                        extra: [
                                            'contacts_processed' => $processed,

                                            'contacts_total' => $total,
                                        ],
                                    );
                            },
                        );

                if (
                    $sync->hubspot_contact_id
                    === null
                    && isset(
                        $contacts[0][
                            'id'
                        ]
                    )
                ) {
                    $sync->hubspot_contact_id =
                        $contacts[0][
                            'id'
                        ];

                    $sync->save();
                }

                $this->updateManualProgress(
                    sync: $sync,
                    progress: 50,
                    step: 'contacts',
                    message: count($contacts)
                        .' contato(s) sincronizado(s) no HubSpot.',
                    extra: [
                        'contacts_processed' => count(
                            $contacts
                        ),

                        'contacts_total' => count(
                            $contacts
                        ),
                    ],
                );

            } else {
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
                            ownerId: null,
                        );

                    $sync->save();
                }

                if (
                    $sync->hubspot_contact_id
                    !== null
                ) {
                    $contacts[] = [
                        'id' => $sync->hubspot_contact_id,

                        'email' => $contact['email']
                            ?? null,

                        'phone' => $contact['phone']
                            ?? null,
                    ];
                }
            }

            /*
             * Em retomadas podemos já ter um
             * contato principal salvo mesmo que
             * ele não apareça mais nos dados
             * cadastrais locais.
             */
            $contactIds =
                array_values(
                    array_unique(
                        array_filter(
                            array_map(
                                static fn (
                                    array $contact
                                ): string => trim(
                                    (string) (
                                        $contact['id']
                                    )
                                ),
                                $contacts
                            ),
                            static fn (
                                string $id
                            ): bool => $id !== ''
                        )
                    )
                );

            if (
                $sync->hubspot_contact_id
                !== null
                && ! in_array(
                    $sync->hubspot_contact_id,
                    $contactIds,
                    true
                )
            ) {
                $contactIds[] =
                    $sync->hubspot_contact_id;
            }

            foreach (
                $contactIds as $contactId
            ) {
                $this->associate(
                    fromType: 'companies',
                    fromId: $sync->hubspot_company_id,
                    toType: 'contacts',
                    toId: $contactId,
                );
            }

            /*
             * NEGÓCIO
             */
            if (
                $sync->hubspot_deal_id
                === null
            ) {
                $sync->hubspot_deal_id =
                    $this->createDeal(
                        company: $company,
                        ownerId: $ownerId,
                        manual: $manual,
                    );

                $sync->save();

            } elseif (
                $manual
            ) {
                $this->updateObject(
                    type: 'deals',
                    id: $sync->hubspot_deal_id,
                    properties: [
                        'hubspot_owner_id' => $ownerId,
                    ],
                );
            }

            $this->associate(
                fromType: 'companies',
                fromId: $sync->hubspot_company_id,
                toType: 'deals',
                toId: $sync->hubspot_deal_id,
            );

            foreach (
                $contactIds as $contactId
            ) {
                $this->associate(
                    fromType: 'contacts',
                    fromId: $contactId,
                    toType: 'deals',
                    toId: $sync->hubspot_deal_id,
                );
            }

            if ($manual) {
                $this->updateManualProgress(
                    sync: $sync,
                    progress: 70,
                    step: 'deal',
                    message: 'Negócio criado e contatos associados.',
                );
            }

            /*
             * TAREFA INICIAL
             *
             * Criada somente no fluxo manual.
             */
            $taskDueAt = null;

            if (
                $manual
                && (bool) config(
                    'services.hubspot.lead_task_enabled',
                    true
                )
            ) {
                $taskDueAt =
                    $this->initialTaskDueAt();

                if (
                    $sync->hubspot_task_id
                    === null
                ) {
                    /*
                     * Primeiro procuramos uma
                     * tarefa equivalente.
                     *
                     * Isso cobre inclusive uma
                     * resposta perdida depois de
                     * o HubSpot já ter criado a
                     * tarefa.
                     */
                    $sync->hubspot_task_id =
                        $this->findInitialTask(
                            company: $company,
                            ownerId: $ownerId,
                        )
                        ?? $this->createInitialTask(
                            company: $company,
                            ownerId: $ownerId,
                            dueAt: $taskDueAt,
                        );

                    $sync->save();
                }

                $this->associate(
                    fromType: 'tasks',
                    fromId: $sync->hubspot_task_id,
                    toType: 'companies',
                    toId: $sync->hubspot_company_id,
                );

                $this->associate(
                    fromType: 'tasks',
                    fromId: $sync->hubspot_task_id,
                    toType: 'deals',
                    toId: $sync->hubspot_deal_id,
                );

                foreach (
                    $contactIds as $contactId
                ) {
                    $this->associate(
                        fromType: 'tasks',
                        fromId: $sync->hubspot_task_id,
                        toType: 'contacts',
                        toId: $contactId,
                    );
                }
            }

            if ($manual) {
                $this->updateManualProgress(
                    sync: $sync,
                    progress: 85,
                    step: 'task',
                    message: 'Tarefa criada e associada aos contatos.',
                );
            }

            /*
             * METADATA
             */
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
                $contacts[0]['email']
                ?? null;

            $metadata[
                'contact_created'
            ] =
                $sync->hubspot_contact_id
                !== null;

            if ($manual) {
                $rawManualMetadata =
                    $metadata[
                        'manual_sync'
                    ]
                    ?? [];

                $manualMetadata =
                    is_array(
                        $rawManualMetadata
                    )
                        ? $rawManualMetadata
                        : [];

                /*
                 * Preservamos status/progresso
                 * definidos pelo Job.
                 *
                 * syncManual() termina a criação
                 * dos objetos remotos, porém
                 * 100% só acontece depois da
                 * reconciliação local.
                 */
                $metadata[
                    'manual_sync'
                ] =
                    array_merge(
                        $manualMetadata,
                        [
                            'initiated_by_user_id' => $actor->id,

                            'initiated_by_email' => $actor->email,

                            'hubspot_owner_id' => $ownerId,

                            'contacts' => $contacts,

                            'contact_ids' => $contactIds,

                            'task_id' => $sync
                                ->hubspot_task_id,

                            'task_due_at' => $taskDueAt
                                ?->toIso8601String(),

                            'remote_objects_created_at' => now()
                                ->toIso8601String(),
                        ]
                    );
            }

            /*
             * Preserva a qualificação original.
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
                    'score' => (int)
                            $scoreSnapshot
                                ->score,

                    'priority' => $scoreSnapshot
                        ->priority,

                    'label' => $scoreSnapshot
                        ->label,

                    'crm_status' => $crmSnapshot
                        ->status,

                    'captured_at' => now()
                        ->toIso8601String(),
                ];
            }

            $sync->forceFill([
                'pipeline_id' => $this->pipelineId(),

                'deal_stage_id' => $this->initialStageId(),

                'synced_at' => now(),

                'sync_error' => null,

                'metadata' => $metadata,
            ])->save();

            $sync =
                $sync->refresh();

            if (! $wasSynced) {
                $this
                    ->activityRecorder
                    ->recordLeadSynced(
                        $sync
                    );
            }

            return $sync;

        } catch (Throwable $exception) {
            $sync->forceFill([
                'sync_error' => mb_substr(
                    $exception
                        ->getMessage(),
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
                'O HubSpot encontrou a empresa, '
                .'mas não retornou um ID válido.'
            );
        }

        $externalId =
            trim(
                (string) $externalId
            );

        if (
            $sync->hubspot_company_id
                !== null
            && $sync->hubspot_company_id
                !== $externalId
        ) {
            throw new RuntimeException(
                'A empresa encontrada no HubSpot '
                .'não corresponde ao registro da '
                .'sincronização em andamento.'
            );
        }

        if (
            $sync->hubspot_company_id
            === null
        ) {
            $sync->hubspot_company_id =
                $externalId;
        }

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
                        $deal['id']
                        ?? null;

                    $name =
                        $deal['name']
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

                    $matches[] =
                        $deal;
                }
            }

            if (count($matches) > 1) {
                throw new RuntimeException(
                    'Mais de um negócio compatível '
                    .'foi encontrado no HubSpot. '
                    .'Sincronização interrompida '
                    .'para evitar duplicidade.'
                );
            }

            if (count($matches) === 1) {
                $match =
                    $matches[0];

                $sync->hubspot_deal_id =
                    (string)
                        $match['id'];

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
        Company $company,
        ?string $ownerId,
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

        if ($ownerId !== null) {
            $properties[
                'hubspot_owner_id'
            ] = $ownerId;
        }

        $cnpjProperty =
            trim(
                (string) config(
                    'services.hubspot.company_cnpj_property'
                )
            );

        $cnpj =
            trim(
                (string) (
                    $matrix->cnpj
                    ?? ''
                )
            );

        if (
            $cnpjProperty !== ''
            && $cnpj !== ''
        ) {
            $properties[
                $cnpjProperty
            ] = $cnpj;
        }

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
        Company $company,
        ?string $ownerId,
        bool $manual,
    ): string {
        $description =
            $manual
                ? 'Oportunidade criada manualmente pelo '
                    .'ExportControl Prospector.'
                : 'Lead criado automaticamente pelo '
                    .'ExportControl Prospector.';

        $description .=
            ' Score SDR: '
            .(
                $company->sdrScore->score
                ?? 0
            )
            .'/100.';

        $properties = [
            'dealname' => $this->dealName(
                $company
            ),

            'pipeline' => $this->pipelineId(),

            'dealstage' => $this->initialStageId(),

            'description' => $description,
        ];

        if ($ownerId !== null) {
            $properties[
                'hubspot_owner_id'
            ] = $ownerId;
        }

        return $this->createObject(
            type: 'deals',
            properties: $properties,
        );
    }

    private function resolveContact(
        Company $company,
        string $email,
        ?string $phone,
        ?string $ownerId,
    ): string {
        $existing =
            $this->findContactByEmail(
                $email
            );

        /*
         * Contato já existente mantém seu
         * owner atual.
         *
         * Não queremos transferir silenciosamente
         * um contato que já pertença a outro
         * vendedor.
         */
        if ($existing !== null) {
            return $existing;
        }

        $properties = [
            'email' => $email,

            'company' => $company
                ->corporate_name,
        ];

        if (
            $phone !== null
            && trim($phone) !== ''
        ) {
            $properties['phone'] =
                $phone;
        }

        if ($ownerId !== null) {
            $properties[
                'hubspot_owner_id'
            ] = $ownerId;
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

    private function findInitialTask(
        Company $company,
        string $ownerId,
    ): ?string {
        $response =
            $this->client()
                ->post(
                    $this->baseUrl()
                    .'/crm/v3/objects/tasks/search',
                    [
                        'filterGroups' => [
                            [
                                'filters' => [
                                    [
                                        'propertyName' => 'hs_task_subject',

                                        'operator' => 'EQ',

                                        'value' => $this
                                            ->initialTaskSubject(
                                                $company
                                            ),
                                    ],

                                    [
                                        'propertyName' => 'hubspot_owner_id',

                                        'operator' => 'EQ',

                                        'value' => $ownerId,
                                    ],
                                ],
                            ],
                        ],

                        'properties' => [
                            'hs_task_subject',
                            'hubspot_owner_id',
                            'hs_task_status',
                            'hs_timestamp',
                        ],

                        'limit' => 10,
                    ]
                );

        $this->ensureSuccess(
            $response,
            'consultar tarefa inicial'
        );

        $data =
            $response->json();

        if (! is_array($data)) {
            return null;
        }

        $results =
            $data['results']
            ?? [];

        if (! is_array($results)) {
            return null;
        }

        $matches = [];

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $id =
                $result['id']
                ?? null;

            if (
                ! is_scalar($id)
                || trim(
                    (string) $id
                ) === ''
            ) {
                continue;
            }

            $matches[] =
                trim(
                    (string) $id
                );
        }

        $matches =
            array_values(
                array_unique(
                    $matches
                )
            );

        if (count($matches) > 1) {
            throw new RuntimeException(
                'Mais de uma tarefa inicial '
                .'compatível foi encontrada no '
                .'HubSpot. A sincronização foi '
                .'interrompida para evitar vínculo '
                .'ambíguo.'
            );
        }

        return $matches[0]
            ?? null;
    }

    private function createInitialTask(
        Company $company,
        string $ownerId,
        CarbonImmutable $dueAt,
    ): string {
        return $this->createObject(
            type: 'tasks',
            properties: [
                'hs_timestamp' => (string) (
                    $dueAt
                        ->getTimestamp()
                    * 1000
                ),

                'hs_task_subject' => $this->initialTaskSubject(
                    $company
                ),

                'hs_task_body' => 'Primeiro contato comercial criado '
                    .'pelo ExportControl Prospector. '
                    .'Empresa: '
                    .$company->corporate_name
                    .'. CNPJ raiz: '
                    .$company->cnpj_root
                    .'.',

                'hs_task_status' => 'NOT_STARTED',

                'hs_task_priority' => $this->taskPriority(),

                'hs_task_type' => $this->taskType(),

                'hubspot_owner_id' => $ownerId,
            ],
        );
    }

    private function initialTaskSubject(
        Company $company
    ): string {
        return mb_substr(
            'Entrar em contato - '
            .$company->corporate_name
            .' - '
            .$company->cnpj_root,
            0,
            240
        );
    }

    private function initialTaskDueAt(): CarbonImmutable
    {
        $timezone =
            trim(
                (string) config(
                    'app.timezone',
                    'UTC'
                )
            );

        if ($timezone === '') {
            $timezone =
                'UTC';
        }

        $configuredHour =
            trim(
                (string) config(
                    'services.hubspot.lead_task_hour',
                    '09:00'
                )
            );

        if (
            preg_match(
                '/^([01]\d|2[0-3]):([0-5]\d)$/',
                $configuredHour,
                $matches
            ) !== 1
        ) {
            $hour = 9;
            $minute = 0;
        } else {
            $hour =
                (int) $matches[1];

            $minute =
                (int) $matches[2];
        }

        $dueAt =
            CarbonImmutable::now(
                $timezone
            )
                ->addDay()
                ->setTime(
                    $hour,
                    $minute
                );

        /*
         * Próximo dia útil simples.
         *
         * Feriados poderão ser integrados
         * futuramente se necessário.
         */
        while ($dueAt->isWeekend()) {
            $dueAt =
                $dueAt->addDay();
        }

        return $dueAt;
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
                'O HubSpot retornou resposta inválida '
                .'ao criar '
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

    /**
     * @param  array<string, string>  $properties
     */
    private function updateObject(
        string $type,
        string $id,
        array $properties,
    ): void {
        $response =
            $this->client()
                ->patch(
                    $this->baseUrl()
                    .'/crm/v3/objects/'
                    .$type
                    .'/'
                    .rawurlencode(
                        $id
                    ),
                    [
                        'properties' => $properties,
                    ]
                );

        $this->ensureSuccess(
            $response,
            'atualizar '.$type
        );
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
                    .rawurlencode(
                        $fromId
                    )
                    .'/associations/default/'
                    .$toType
                    .'/'
                    .rawurlencode(
                        $toId
                    )
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
     * @return list<array{
     *     email: string,
     *     phone: string|null
     * }>
     */
    private function contactCandidates(
        Company $company
    ): array {
        $establishments =
            $company
                ->establishments
                ->sortByDesc(
                    function (
                        $establishment
                    ): int {
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

        $contacts = [];

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

            /*
             * Deduplicação por e-mail.
             */
            if (
                isset(
                    $contacts[
                        $email
                    ]
                )
            ) {
                continue;
            }

            $phone =
                trim(
                    (string)
                        $establishment
                            ->phone_1
                );

            if ($phone === '') {
                $phone =
                    trim(
                        (string)
                            $establishment
                                ->phone_2
                    );
            }

            $contacts[
                $email
            ] = [
                'email' => $email,

                'phone' => $phone !== ''
                        ? $phone
                        : null,
            ];
        }

        return array_values(
            $contacts
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
        $contacts =
            $this->contactCandidates(
                $company
            );

        return $contacts[0]
            ?? null;
    }

    private function domainFromEmail(
        string $email
    ): ?string {
        $parts =
            explode(
                '@',
                mb_strtolower(
                    trim(
                        $email
                    )
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

    /**
     * @param  array<string, mixed>  $extra
     */
    private function updateManualProgress(
        CompanyHubSpotLead $sync,
        int $progress,
        string $step,
        string $message,
        array $extra = [],
    ): void {
        $rawMetadata =
            $sync->getAttribute(
                'metadata'
            );

        $metadata =
            is_array(
                $rawMetadata
            )
                ? $rawMetadata
                : [];

        $rawManual =
            $metadata[
                'manual_sync'
            ]
            ?? [];

        $manual =
            is_array(
                $rawManual
            )
                ? $rawManual
                : [];

        $current =
            is_numeric(
                $manual[
                    'progress'
                ]
                ?? null
            )
                ? (int) $manual[
                    'progress'
                ]
                : 0;

        $metadata[
            'manual_sync'
        ] =
            array_merge(
                $manual,
                [
                    'progress' => max(
                        $current,
                        min(
                            100,
                            $progress
                        )
                    ),

                    'step' => $step,

                    'progress_message' => $message,

                    'progress_updated_at' => now()
                        ->toIso8601String(),
                ],
                $extra,
            );

        $sync->forceFill([
            'metadata' => $metadata,
        ])->save();
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

    private function taskPriority(): string
    {
        $priority =
            mb_strtoupper(
                trim(
                    (string) config(
                        'services.hubspot.lead_task_priority',
                        'HIGH'
                    )
                )
            );

        return in_array(
            $priority,
            [
                'LOW',
                'MEDIUM',
                'HIGH',
            ],
            true
        )
            ? $priority
            : 'HIGH';
    }

    private function taskType(): string
    {
        $type =
            mb_strtoupper(
                trim(
                    (string) config(
                        'services.hubspot.lead_task_type',
                        'CALL'
                    )
                )
            );

        return in_array(
            $type,
            [
                'CALL',
                'EMAIL',
                'TODO',
            ],
            true
        )
            ? $type
            : 'CALL';
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
