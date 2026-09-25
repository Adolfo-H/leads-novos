<?php

namespace App\Services;

use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\HubSpotTask;
use App\Models\HubSpotWebhookEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class HubSpotWebhookMirrorSyncService
{
    /**
     * @var array<string, array{
     *     pipeline_label: string,
     *     stage_label: string,
     *     display_order: int|null
     * }>|null
     */
    private ?array $pipelineStages = null;

    public function __construct(
        private readonly HubSpotWebhookEventContextService $context,
    ) {}

    /**
     * Atualiza o espelho HubSpot independentemente
     * de já existir ou não Company/CNPJ fiscal.
     *
     * Retorna true quando pelo menos um objeto
     * do mirror foi tratado.
     */
    public function syncEvent(
        HubSpotWebhookEvent $event
    ): bool {
        $handled = false;

        foreach (
            $this->context->references(
                $event
            ) as $reference
        ) {
            $type =
                $reference['type'];

            if (
                ! in_array(
                    $type,
                    [
                        'company',
                        'deal',
                        'contact',
                        'task',
                    ],
                    true
                )
            ) {
                continue;
            }

            $isMainObject =
                $type
                    === $event->object_type
                && $reference['id']
                    === $event->object_id;

            if (
                $isMainObject
                && $this->isDeletion(
                    $event
                )
            ) {
                $this->deleteObject(
                    $type,
                    $reference['id'],
                );

                $handled = true;

                continue;
            }

            $this->syncObject(
                type: $type,
                objectId: $reference['id'],
            );

            $handled = true;
        }

        return $handled;
    }

    private function syncObject(
        string $type,
        string $objectId,
    ): void {
        match ($type) {
            'company' => $this->syncCompany(
                $objectId
            ),

            'deal' => $this->syncDeal(
                $objectId
            ),

            'contact' => $this->syncContact(
                $objectId
            ),

            'task' => $this->syncTask(
                $objectId
            ),

            default => null,
        };
    }

    private function syncCompany(
        string $objectId
    ): void {
        $record =
            $this->readObject(
                type: 'companies',

                id: $objectId,

                properties: [
                    'name',
                    'domain',
                    'lifecyclestage',
                    'hs_lead_status',
                    'hubspot_owner_id',
                    'city',
                    'state',
                    'phone',
                    'notes_last_contacted',
                    'notes_last_updated',
                    'num_contacted_notes',
                    'num_associated_deals',
                ],
            );

        if ($record === null) {
            $this->deleteObject(
                'company',
                $objectId
            );

            return;
        }

        $properties =
            $record['properties'];

        $company =
            HubSpotCompany::query()
                ->firstOrNew([
                    'hubspot_id' => $objectId,
                ]);

        $raw =
            $this->mergedProperties(
                $company,
                $properties
            );

        /*
         * Mantemos também as chaves legadas
         * vindas do CSV porque o CRM Mirror
         * já sabe interpretá-las.
         */
        if (
            array_key_exists(
                'num_contacted_notes',
                $properties
            )
        ) {
            $raw[
                'Número de contatos efetuados'
            ] =
                $properties[
                    'num_contacted_notes'
                ];
        }

        if (
            array_key_exists(
                'notes_last_contacted',
                $properties
            )
        ) {
            $raw[
                'Último contato'
            ] =
                $properties[
                    'notes_last_contacted'
                ];
        }

        $company->forceFill([
            'name' => $this->stringValue(
                $properties[
                    'name'
                ]
                ?? null
            ),

            'domain' => $this->normalizeDomain(
                $properties[
                    'domain'
                ]
                ?? null
            ),

            'lifecycle_stage' => $this->stringValue(
                $properties[
                    'lifecyclestage'
                ]
                ?? null
            ),

            'lead_status' => $this->stringValue(
                $properties[
                    'hs_lead_status'
                ]
                ?? null
            ),

            'owner_name' => $this->ownerName(
                $properties[
                    'hubspot_owner_id'
                ]
                ?? null,
                $company->owner_name,
            ),

            'city' => $this->stringValue(
                $properties[
                    'city'
                ]
                ?? null
            ),

            'state' => $this->stringValue(
                $properties[
                    'state'
                ]
                ?? null
            ),

            'phone' => $this->stringValue(
                $properties[
                    'phone'
                ]
                ?? null
            ),

            'last_activity_at' => $this->latestDate(
                [
                    $properties[
                        'notes_last_contacted'
                    ]
                    ?? null,

                    $properties[
                        'notes_last_updated'
                    ]
                    ?? null,
                ]
            ),

            'hubspot_created_at' => $record[
                    'created_at'
                ],

            'hubspot_updated_at' => $record[
                    'updated_at'
                ],

            'raw_properties' => $raw,
        ])->save();

        $this->syncCompanyAssociations(
            $company
        );
    }

    private function syncDeal(
        string $objectId
    ): void {
        $record =
            $this->readObject(
                type: 'deals',

                id: $objectId,

                properties: [
                    'dealname',
                    'pipeline',
                    'dealstage',
                    'hubspot_owner_id',
                    'amount',
                    'deal_currency_code',
                    'hs_is_closed',
                    'hs_is_closed_won',
                    'closedate',
                    'notes_last_contacted',
                    'notes_last_updated',
                ],
            );

        if ($record === null) {
            $this->deleteObject(
                'deal',
                $objectId
            );

            return;
        }

        $properties =
            $record['properties'];

        $pipelineId =
            $this->stringValue(
                $properties[
                    'pipeline'
                ]
                ?? null
            );

        $stageId =
            $this->stringValue(
                $properties[
                    'dealstage'
                ]
                ?? null
            );

        $stageInfo =
            $this->stageInfo(
                $pipelineId,
                $stageId,
            );

        $isClosed =
            $this->booleanValue(
                $properties[
                    'hs_is_closed'
                ]
                ?? false
            );

        $isWon =
            $this->booleanValue(
                $properties[
                    'hs_is_closed_won'
                ]
                ?? false
            );

        $pipelineStageId =
            $this->storePipelineStage(
                pipelineId: $pipelineId,

                stageId: $stageId,

                pipelineLabel: $stageInfo[
                        'pipeline_label'
                    ],

                stageLabel: $stageInfo[
                        'stage_label'
                    ],

                displayOrder: $stageInfo[
                        'display_order'
                    ],

                isClosed: $isClosed,

                isWon: $isWon,
            );

        $deal =
            HubSpotDeal::query()
                ->firstOrNew([
                    'hubspot_id' => $objectId,
                ]);

        $deal->forceFill([
            'hubspot_pipeline_stage_id' => $pipelineStageId,

            'name' => $this->stringValue(
                $properties[
                    'dealname'
                ]
                ?? null
            ),

            'pipeline_id' => $pipelineId,

            'pipeline_label' => $stageInfo[
                    'pipeline_label'
                ],

            'stage_id' => $stageId,

            'stage_label' => $stageInfo[
                    'stage_label'
                ],

            'owner_name' => $this->ownerName(
                $properties[
                    'hubspot_owner_id'
                ]
                ?? null,
                $deal->owner_name,
            ),

            'amount' => $this->decimalValue(
                $properties[
                    'amount'
                ]
                ?? null
            ),

            'currency' => $this->stringValue(
                $properties[
                    'deal_currency_code'
                ]
                ?? null
            ),

            'is_closed' => $isClosed,

            'is_closed_won' => $isWon,

            'hubspot_created_at' => $record[
                    'created_at'
                ],

            'closed_at' => $this->dateValue(
                $properties[
                    'closedate'
                ]
                ?? null
            ),

            'last_activity_at' => $this->latestDate(
                [
                    $properties[
                        'notes_last_contacted'
                    ]
                    ?? null,

                    $properties[
                        'notes_last_updated'
                    ]
                    ?? null,
                ]
            ),

            'raw_properties' => $this->mergedProperties(
                $deal,
                $properties
            ),
        ])->save();

        $this->syncDealAssociations(
            $deal
        );
    }

    private function syncContact(
        string $objectId
    ): void {
        $record =
            $this->readObject(
                type: 'contacts',

                id: $objectId,

                properties: [
                    'firstname',
                    'lastname',
                    'email',
                    'phone',
                    'mobilephone',
                    'jobtitle',
                    'company',
                    'hubspot_owner_id',
                    'lifecyclestage',
                    'notes_last_contacted',
                    'notes_last_updated',
                ],
            );

        if ($record === null) {
            $this->deleteObject(
                'contact',
                $objectId
            );

            return;
        }

        $properties =
            $record['properties'];

        $contact =
            HubSpotContact::query()
                ->firstOrNew([
                    'hubspot_id' => $objectId,
                ]);

        $contact->forceFill([
            'first_name' => $this->stringValue(
                $properties[
                    'firstname'
                ]
                ?? null
            ),

            'last_name' => $this->stringValue(
                $properties[
                    'lastname'
                ]
                ?? null
            ),

            'email' => $this->stringValue(
                $properties[
                    'email'
                ]
                ?? null
            ),

            'phone' => $this->stringValue(
                $properties[
                    'phone'
                ]
                ?? null
            ),

            'mobile_phone' => $this->stringValue(
                $properties[
                    'mobilephone'
                ]
                ?? null
            ),

            'job_title' => $this->stringValue(
                $properties[
                    'jobtitle'
                ]
                ?? null
            ),

            'company_name' => $this->stringValue(
                $properties[
                    'company'
                ]
                ?? null
            ),

            'owner_name' => $this->ownerName(
                $properties[
                    'hubspot_owner_id'
                ]
                ?? null,
                $contact->owner_name,
            ),

            'lifecycle_stage' => $this->stringValue(
                $properties[
                    'lifecyclestage'
                ]
                ?? null
            ),

            'last_activity_at' => $this->latestDate(
                [
                    $properties[
                        'notes_last_contacted'
                    ]
                    ?? null,

                    $properties[
                        'notes_last_updated'
                    ]
                    ?? null,
                ]
            ),

            'hubspot_created_at' => $record[
                    'created_at'
                ],

            'raw_properties' => $this->mergedProperties(
                $contact,
                $properties
            ),
        ])->save();

        $this->syncContactAssociations(
            $contact
        );
    }

    private function syncTask(
        string $objectId
    ): void {
        $record =
            $this->readObject(
                type: 'tasks',

                id: $objectId,

                properties: [
                    'hs_timestamp',
                    'hs_task_subject',
                    'hs_task_body',
                    'hs_task_status',
                    'hs_task_priority',
                    'hs_task_type',
                    'hubspot_owner_id',
                ],
            );

        if ($record === null) {
            $this->deleteObject(
                'task',
                $objectId
            );

            return;
        }

        $properties =
            $record['properties'];

        $status =
            $this->stringValue(
                $properties[
                    'hs_task_status'
                ]
                ?? null
            );

        $dueAt =
            $this->dateValue(
                $properties[
                    'hs_timestamp'
                ]
                ?? null
            );

        $isOpen =
            mb_strtoupper(
                (string)
                $status
            ) !== 'COMPLETED';

        $task =
            HubSpotTask::query()
                ->firstOrNew([
                    'hubspot_id' => $objectId,
                ]);

        $task->forceFill([
            'title' => $this->stringValue(
                $properties[
                    'hs_task_subject'
                ]
                ?? null
            ),

            'status' => $status,

            'stage' => null,

            'type' => $this->stringValue(
                $properties[
                    'hs_task_type'
                ]
                ?? null
            ),

            'assigned_to' => $this->ownerName(
                $properties[
                    'hubspot_owner_id'
                ]
                ?? null,
                $task->assigned_to,
            ),

            'is_open' => $isOpen,

            'is_overdue' => $isOpen
                && $dueAt !== null
                && $dueAt->isPast(),

            'due_at' => $dueAt,

            'completed_at' => ! $isOpen
                    ? $record[
                        'updated_at'
                    ]
                    : null,

            'hubspot_created_at' => $record[
                    'created_at'
                ],

            'notes' => $this->stringValue(
                $properties[
                    'hs_task_body'
                ]
                ?? null
            ),

            'raw_properties' => $this->mergedProperties(
                $task,
                $properties
            ),
        ])->save();

        $this->syncTaskAssociations(
            $task
        );
    }

    private function syncCompanyAssociations(
        HubSpotCompany $company
    ): void {
        $company->deals()->sync(
            $this->localIds(
                HubSpotDeal::class,
                $this->associationIds(
                    'companies',
                    $company->hubspot_id,
                    'deals',
                )
            )
        );

        $company->contacts()->sync(
            $this->localIds(
                HubSpotContact::class,
                $this->associationIds(
                    'companies',
                    $company->hubspot_id,
                    'contacts',
                )
            )
        );

        $company->tasks()->sync(
            $this->localIds(
                HubSpotTask::class,
                $this->associationIds(
                    'companies',
                    $company->hubspot_id,
                    'tasks',
                )
            )
        );
    }

    private function syncDealAssociations(
        HubSpotDeal $deal
    ): void {
        $deal->companies()->sync(
            $this->localIds(
                HubSpotCompany::class,
                $this->associationIds(
                    'deals',
                    $deal->hubspot_id,
                    'companies',
                )
            )
        );

        $deal->contacts()->sync(
            $this->localIds(
                HubSpotContact::class,
                $this->associationIds(
                    'deals',
                    $deal->hubspot_id,
                    'contacts',
                )
            )
        );

        $deal->tasks()->sync(
            $this->localIds(
                HubSpotTask::class,
                $this->associationIds(
                    'deals',
                    $deal->hubspot_id,
                    'tasks',
                )
            )
        );
    }

    private function syncContactAssociations(
        HubSpotContact $contact
    ): void {
        $contact->companies()->sync(
            $this->localIds(
                HubSpotCompany::class,
                $this->associationIds(
                    'contacts',
                    $contact->hubspot_id,
                    'companies',
                )
            )
        );

        $contact->deals()->sync(
            $this->localIds(
                HubSpotDeal::class,
                $this->associationIds(
                    'contacts',
                    $contact->hubspot_id,
                    'deals',
                )
            )
        );

        $contact->tasks()->sync(
            $this->localIds(
                HubSpotTask::class,
                $this->associationIds(
                    'contacts',
                    $contact->hubspot_id,
                    'tasks',
                )
            )
        );
    }

    private function syncTaskAssociations(
        HubSpotTask $task
    ): void {
        $task->companies()->sync(
            $this->localIds(
                HubSpotCompany::class,
                $this->associationIds(
                    'tasks',
                    $task->hubspot_id,
                    'companies',
                )
            )
        );

        $task->deals()->sync(
            $this->localIds(
                HubSpotDeal::class,
                $this->associationIds(
                    'tasks',
                    $task->hubspot_id,
                    'deals',
                )
            )
        );

        $task->contacts()->sync(
            $this->localIds(
                HubSpotContact::class,
                $this->associationIds(
                    'tasks',
                    $task->hubspot_id,
                    'contacts',
                )
            )
        );
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  list<string>  $externalIds
     * @return list<int>
     */
    private function localIds(
        string $modelClass,
        array $externalIds,
    ): array {
        $ids = [];

        foreach (
            $externalIds as $externalId
        ) {
            $model =
                $modelClass::query()
                    ->firstOrCreate([
                        'hubspot_id' => $externalId,
                    ]);

            $ids[] =
                (int) $model->getKey();
        }

        return array_values(
            array_unique(
                $ids
            )
        );
    }

    /**
     * @param  list<string>  $properties
     * @return array{
     *     properties: array<string, mixed>,
     *     created_at: CarbonImmutable|null,
     *     updated_at: CarbonImmutable|null
     * }|null
     */
    private function readObject(
        string $type,
        string $id,
        array $properties,
    ): ?array {
        try {
            $response =
                $this->client()
                    ->get(
                        $this->baseUrl()
                        .'/crm/v3/objects/'
                        .$type
                        .'/'
                        .rawurlencode(
                            $id
                        ),
                        [
                            'properties' => implode(
                                ',',
                                $properties
                            ),
                        ]
                    );
        } catch (
            ConnectionException $exception
        ) {
            throw new RuntimeException(
                'Não foi possível consultar objeto HubSpot.',
                previous: $exception,
            );
        }

        if (
            $response->status()
            === 404
        ) {
            return null;
        }

        $this->ensureSuccess(
            $response
        );

        $data =
            $response->json();

        if (! is_array($data)) {
            throw new RuntimeException(
                'Resposta inválida do HubSpot.'
            );
        }

        $rawProperties =
            $data[
                'properties'
            ]
            ?? [];

        return [
            'properties' => is_array(
                $rawProperties
            )
                    ? $rawProperties
                    : [],

            'created_at' => $this->dateValue(
                $data[
                    'createdAt'
                ]
                ?? null
            ),

            'updated_at' => $this->dateValue(
                $data[
                    'updatedAt'
                ]
                ?? null
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function associationIds(
        string $fromType,
        string $fromId,
        string $toType,
    ): array {
        $response =
            $this->client()
                ->get(
                    $this->baseUrl()
                    .'/crm/v4/objects/'
                    .$fromType
                    .'/'
                    .rawurlencode(
                        $fromId
                    )
                    .'/associations/'
                    .$toType,
                    [
                        'limit' => 100,
                    ]
                );

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
                    (string)
                    $id
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

    private function ownerName(
        mixed $ownerId,
        ?string $fallback = null,
    ): ?string {
        $ownerId =
            $this->stringValue(
                $ownerId
            );

        /*
         * Sem owner no objeto significa que a
         * atribuição foi realmente removida.
         */
        if ($ownerId === null) {
            return null;
        }

        try {
            $response =
                $this->client()
                    ->get(
                        $this->baseUrl()
                        .'/crm/v3/owners/'
                        .rawurlencode(
                            $ownerId
                        )
                    );
        } catch (
            ConnectionException
        ) {
            /*
             * Owner é enriquecimento.
             *
             * Falha nessa consulta não pode
             * impedir Company/Deal/Task/etc.
             * de serem sincronizados.
             */
            return $fallback;
        }

        /*
         * O token atual possui acesso aos
         * objetos CRM necessários ao Prospector,
         * mas pode não possuir permissão para
         * consultar Owners.
         *
         * Nesse caso mantemos o último nome
         * conhecido e seguimos o webhook.
         */
        if (
            $response->status()
            === 403
        ) {
            return $fallback;
        }

        if (
            $response->status()
            === 404
        ) {
            return null;
        }

        $this->ensureSuccess(
            $response
        );

        $data =
            $response->json();

        if (! is_array($data)) {
            return $fallback;
        }

        $name =
            trim(
                implode(
                    ' ',
                    array_filter([
                        $this->stringValue(
                            $data[
                                'firstName'
                            ]
                            ?? null
                        ),

                        $this->stringValue(
                            $data[
                                'lastName'
                            ]
                            ?? null
                        ),
                    ])
                )
            );

        if ($name !== '') {
            return $name;
        }

        return $this->stringValue(
            $data[
                'email'
            ]
            ?? null
        )
            ?? $fallback;
    }

    /**
     * @return array{
     *     pipeline_label: string|null,
     *     stage_label: string|null,
     *     display_order: int|null
     * }
     */
    private function stageInfo(
        ?string $pipelineId,
        ?string $stageId,
    ): array {
        if (
            $pipelineId === null
            || $stageId === null
        ) {
            return [
                'pipeline_label' => $pipelineId,

                'stage_label' => $stageId,

                'display_order' => null,
            ];
        }

        $map =
            $this->pipelineStageMap();

        return $map[
            $pipelineId
            .'|'
            .$stageId
        ]
            ?? [
                'pipeline_label' => $pipelineId,

                'stage_label' => $stageId,

                'display_order' => null,
            ];
    }

    /**
     * @return array<string, array{
     *     pipeline_label: string,
     *     stage_label: string,
     *     display_order: int|null
     * }>
     */
    private function pipelineStageMap(): array
    {
        if (
            $this->pipelineStages
            !== null
        ) {
            return $this->pipelineStages;
        }

        $response =
            $this->client()
                ->get(
                    $this->baseUrl()
                    .'/crm/v3/pipelines/deals'
                );

        if ($response->failed()) {
            $this->pipelineStages = [];

            return [];
        }

        $data =
            $response->json();

        $pipelines =
            is_array($data)
                ? (
                    $data[
                        'results'
                    ]
                    ?? []
                )
                : [];

        $map = [];

        if (is_array($pipelines)) {
            foreach (
                $pipelines as $pipeline
            ) {
                if (! is_array($pipeline)) {
                    continue;
                }

                $pipelineId =
                    $this->stringValue(
                        $pipeline[
                            'id'
                        ]
                        ?? null
                    );

                if ($pipelineId === null) {
                    continue;
                }

                $pipelineLabel =
                    $this->stringValue(
                        $pipeline[
                            'label'
                        ]
                        ?? null
                    )
                    ?? $pipelineId;

                $stages =
                    $pipeline[
                        'stages'
                    ]
                    ?? [];

                if (! is_array($stages)) {
                    continue;
                }

                foreach ($stages as $stage) {
                    if (! is_array($stage)) {
                        continue;
                    }

                    $stageId =
                        $this->stringValue(
                            $stage[
                                'id'
                            ]
                            ?? null
                        );

                    if ($stageId === null) {
                        continue;
                    }

                    $map[
                        $pipelineId
                        .'|'
                        .$stageId
                    ] = [
                        'pipeline_label' => $pipelineLabel,

                        'stage_label' => $this->stringValue(
                            $stage[
                                'label'
                            ]
                            ?? null
                        )
                            ?? $stageId,

                        'display_order' => is_numeric(
                            $stage[
                                'displayOrder'
                            ]
                            ?? null
                        )
                                ? (int)
                                    $stage[
                                        'displayOrder'
                                    ]
                                : null,
                    ];
                }
            }
        }

        $this->pipelineStages =
            $map;

        return $map;
    }

    private function storePipelineStage(
        ?string $pipelineId,
        ?string $stageId,
        ?string $pipelineLabel,
        ?string $stageLabel,
        ?int $displayOrder,
        bool $isClosed,
        bool $isWon,
    ): ?int {
        if (
            $pipelineId === null
            || $stageId === null
        ) {
            return null;
        }

        $pipelineLabel ??=
            $pipelineId;

        $stageLabel ??=
            $stageId;

        $stage =
            HubSpotPipelineStage::query()
                ->where(
                    'pipeline_id',
                    $pipelineId
                )
                ->where(
                    'stage_id',
                    $stageId
                )
                ->first();

        if ($stage === null) {
            /*
             * O mirror antigo foi montado a
             * partir do CSV e pode possuir só
             * labels, sem IDs.
             */
            $stage =
                HubSpotPipelineStage::query()
                    ->where(
                        'pipeline_label',
                        $pipelineLabel
                    )
                    ->where(
                        'stage_label',
                        $stageLabel
                    )
                    ->first();
        }

        $stage ??=
            new HubSpotPipelineStage;

        $stage->forceFill([
            'pipeline_id' => $pipelineId,

            'pipeline_label' => $pipelineLabel,

            'stage_id' => $stageId,

            'stage_label' => $stageLabel,

            'display_order' => $displayOrder,

            'is_closed' => $isClosed,

            'is_closed_won' => $isWon,
        ])->save();

        return (int)
            $stage->id;
    }

    private function deleteObject(
        string $type,
        string $objectId,
    ): void {
        match ($type) {
            'company' => HubSpotCompany::query()
                ->where(
                    'hubspot_id',
                    $objectId
                )
                ->delete(),

            'deal' => HubSpotDeal::query()
                ->where(
                    'hubspot_id',
                    $objectId
                )
                ->delete(),

            'contact' => HubSpotContact::query()
                ->where(
                    'hubspot_id',
                    $objectId
                )
                ->delete(),

            'task' => HubSpotTask::query()
                ->where(
                    'hubspot_id',
                    $objectId
                )
                ->delete(),

            default => null,
        };
    }

    private function isDeletion(
        HubSpotWebhookEvent $event
    ): bool {
        return str_contains(
            mb_strtolower(
                $event
                    ->subscription_type
            ),
            'deletion'
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function mergedProperties(
        Model $model,
        array $properties,
    ): array {
        $raw =
            $model->getAttribute(
                'raw_properties'
            );

        return array_merge(
            is_array($raw)
                ? $raw
                : [],

            $properties,
        );
    }

    private function normalizeDomain(
        mixed $value
    ): ?string {
        $value =
            $this->stringValue(
                $value
            );

        if ($value === null) {
            return null;
        }

        $value =
            mb_strtolower(
                $value
            );

        $value =
            preg_replace(
                '#^https?://#',
                '',
                $value
            )
            ?? $value;

        $value =
            explode(
                '/',
                $value
            )[0];

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

    private function decimalValue(
        mixed $value
    ): ?string {
        if (! is_numeric($value)) {
            return null;
        }

        return number_format(
            (float)
            $value,
            2,
            '.',
            ''
        );
    }

    private function booleanValue(
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
                trim(
                    $value
                )
            ),
            [
                '1',
                'true',
                'yes',
                'sim',
            ],
            true
        );
    }

    /**
     * @param  list<mixed>  $values
     */
    private function latestDate(
        array $values
    ): ?CarbonImmutable {
        $latest = null;

        foreach ($values as $value) {
            $date =
                $this->dateValue(
                    $value
                );

            if (
                $date !== null
                && (
                    $latest === null
                    || $date->greaterThan(
                        $latest
                    )
                )
            ) {
                $latest =
                    $date;
            }
        }

        return $latest;
    }

    private function dateValue(
        mixed $value
    ): ?CarbonImmutable {
        if (
            $value instanceof CarbonInterface
        ) {
            return CarbonImmutable::instance(
                $value
            );
        }

        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $number =
                    (int) $value;

                return
                    $number
                    > 100000000000
                        ? CarbonImmutable::createFromTimestampMs(
                            $number
                        )
                        : CarbonImmutable::createFromTimestamp(
                            $number
                        );
            }

            if (is_scalar($value)) {
                return CarbonImmutable::parse(
                    (string)
                    $value
                );
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function stringValue(
        mixed $value
    ): ?string {
        if (! is_scalar($value)) {
            return null;
        }

        $value =
            trim(
                (string) $value
            );

        return
            $value !== ''
                ? $value
                : null;
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
                'Token sem permissão para sincronizar o mirror HubSpot.'
            );
        }

        if (
            $response->status()
            === 429
        ) {
            throw new RuntimeException(
                'Limite da API HubSpot atingido.'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Falha ao sincronizar mirror HubSpot. HTTP '
                .$response->status()
                .'.'
            );
        }
    }
}
