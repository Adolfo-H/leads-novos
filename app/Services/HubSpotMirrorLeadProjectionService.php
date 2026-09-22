<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class HubSpotMirrorLeadProjectionService
{
    public function __construct(
        private readonly LeadOperationalClassificationService $classification,
        private readonly LeadQualificationScoreService $qualification,
    ) {}

    /**
     * @return array{
     *     hubspot_company_id: string|null,
     *     hubspot_contact_id: null,
     *     hubspot_deal_id: string|null,
     *     pipeline_id: string|null,
     *     deal_stage_id: string|null,
     *     work_status: string,
     *     commercial_status: string,
     *     last_activity_type: string|null,
     *     last_activity_at: CarbonImmutable|null,
     *     open_task_count: int,
     *     last_task_due_at: CarbonImmutable|null,
     *     owner_names: list<string>,
     *     metadata: array<string, mixed>
     * }
     */
    public function build(
        Company $company
    ): array {
        $company->loadMissing([
            'crmCheck',
            'icpScore',
            'exportIntelligence',
        ]);

        $crm =
            $company->crmCheck;

        $rawMetadata =
            $crm?->getAttribute(
                'metadata'
            );

        $metadata =
            is_array(
                $rawMetadata
            )
                ? $rawMetadata
                : [];

        $deals =
            $this->validArrays(
                $metadata[
                    'deals'
                ]
                ?? []
            );

        $openTasks =
            $this->validArrays(
                $metadata[
                    'open_tasks'
                ]
                ?? []
            );

        $ownerNames =
            $this->strings(
                $metadata[
                    'owner_names'
                ]
                ?? []
            );

        $representativeDeal =
            $this->representativeDeal(
                $deals
            );

        $workStatus =
            $this
                ->classification
                ->workStatus(
                    $crm,
                    $openTasks
                );

        $commercialStatus =
            $this
                ->classification
                ->commercialStatus(
                    $crm
                );

        $nextTaskDueAt =
            $this->nextTaskDueAt(
                $openTasks
            );

        $lastActivityAt =
            $this->parseDate(
                $crm?->getAttribute(
                    'last_contacted_at'
                )
            );

        $projectionTasks = [];

        foreach (
            $openTasks as $task
        ) {
            $title =
                trim(
                    (string) (
                        $task[
                            'title'
                        ]
                        ?? ''
                    )
                );

            $projectionTasks[] = [
                'id' => isset(
                    $task[
                        'id'
                    ]
                )
                        ? (string) $task[
                            'id'
                        ]
                        : null,

                /*
                 * A tela antiga procura
                 * "subject".
                 */
                'subject' => $title !== ''
                        ? $title
                        : 'Tarefa HubSpot',

                'due_at' => $this->dateString(
                    $task[
                        'due_at'
                    ]
                    ?? null
                ),

                'status' => $task[
                        'status'
                    ]
                    ?? null,

                'type' => $task[
                        'type'
                    ]
                    ?? null,

                'assigned_to' => $task[
                        'assigned_to'
                    ]
                    ?? null,
            ];
        }

        $hubSpotCompanyIds =
            $this->strings(
                $metadata[
                    'hubspot_company_ids'
                ]
                ?? []
            );

        $hubSpotCompanyId =
            trim(
                (string) (
                    $crm->external_id ?? ''
                )
            );

        if (
            $hubSpotCompanyId === ''
            && $hubSpotCompanyIds !== []
        ) {
            $hubSpotCompanyId =
                $hubSpotCompanyIds[0];
        }

        return [
            'hubspot_company_id' => $hubSpotCompanyId !== ''
                    ? $hubSpotCompanyId
                    : null,

            /*
             * Um único contato não representa
             * corretamente o novo modelo.
             *
             * Contatos permanecem no mirror.
             */
            'hubspot_contact_id' => null,

            /*
             * Este é apenas o negócio
             * representativo da projeção.
             *
             * Todos continuam preservados
             * em metadata.deals / hubspot_deals.
             */
            'hubspot_deal_id' => $this->dealString(
                $representativeDeal,
                'id'
            ),

            'pipeline_id' => $this->dealString(
                $representativeDeal,
                'pipeline_id'
            ),

            'deal_stage_id' => $this->dealString(
                $representativeDeal,
                'stage_id'
            ),

            'work_status' => $workStatus,

            'commercial_status' => $commercialStatus,

            'last_activity_type' => $lastActivityAt !== null
                    ? 'hubspot_activity'
                    : null,

            'last_activity_at' => $lastActivityAt,

            'open_task_count' => count(
                $openTasks
            ),

            /*
             * Apesar do nome legado da coluna,
             * usamos a próxima tarefa aberta.
             */
            'last_task_due_at' => $nextTaskDueAt,

            'owner_names' => $ownerNames,

            'metadata' => [
                'projection' => [
                    'source' => 'hubspot_mirror',

                    'generated_at' => now()
                        ->toIso8601String(),

                    'source_of_truth' => false,

                    'representative_deal_only' => true,
                ],

                'hubspot_status' => [
                    'open_tasks' => $projectionTasks,

                    'open_task_count' => count(
                        $projectionTasks
                    ),

                    'work_status' => $workStatus,

                    'commercial_status' => $commercialStatus,
                ],

                /*
                 * Todos os negócios continuam
                 * disponíveis aqui também para
                 * compatibilidade.
                 */
                'deals' => $deals,

                'deal_summary' => $metadata[
                        'deal_summary'
                    ]
                    ?? null,

                'hubspot_company_ids' => $hubSpotCompanyIds,

                'owner_names' => $ownerNames,

                /*
                 * Mantém o Score comercial
                 * visível mesmo quando cliente /
                 * oportunidade são bloqueados
                 * pelo SDR operacional.
                 */
                'qualification_snapshot' => $this->qualificationSnapshot(
                    $company
                ),
            ],
        ];
    }

    public function sync(
        Company $company
    ): CompanyHubSpotLead {
        $projection =
            $this->build(
                $company
            );

        $existing =
            CompanyHubSpotLead::query()
                ->where(
                    'company_id',
                    $company->id
                )
                ->first();

        $previousStatus =
            $existing?->work_status;

        $nextStatus =
            $projection[
                'work_status'
            ];

        $statusChangedAt =
            $existing
                ?->work_status_changed_at;

        if (
            $existing === null
            || $previousStatus
                !== $nextStatus
        ) {
            $statusChangedAt =
                now();
        }

        return CompanyHubSpotLead::query()
            ->updateOrCreate(
                [
                    'company_id' => $company->id,
                ],
                [
                    'hubspot_company_id' => $projection[
                            'hubspot_company_id'
                        ],

                    'hubspot_contact_id' => null,

                    'hubspot_deal_id' => $projection[
                            'hubspot_deal_id'
                        ],

                    'pipeline_id' => $projection[
                            'pipeline_id'
                        ],

                    'deal_stage_id' => $projection[
                            'deal_stage_id'
                        ],

                    'work_status' => $nextStatus,

                    'commercial_status' => $projection[
                            'commercial_status'
                        ],

                    'work_status_changed_at' => $statusChangedAt,

                    'last_activity_type' => $projection[
                            'last_activity_type'
                        ],

                    'last_activity_at' => $projection[
                            'last_activity_at'
                        ],

                    'open_task_count' => $projection[
                            'open_task_count'
                        ],

                    'last_task_due_at' => $projection[
                            'last_task_due_at'
                        ],

                    'synced_at' => now(),

                    'status_synced_at' => now(),

                    'sync_error' => null,

                    'metadata' => $projection[
                            'metadata'
                        ],
                ]
            )
            ->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $deals
     * @return array<string, mixed>
     */
    private function representativeDeal(
        array $deals
    ): array {
        if ($deals === []) {
            return [];
        }

        usort(
            $deals,
            function (
                array $left,
                array $right
            ): int {
                $leftRank =
                    $this->dealRank(
                        $left
                    );

                $rightRank =
                    $this->dealRank(
                        $right
                    );

                if (
                    $leftRank
                    !== $rightRank
                ) {
                    return $leftRank
                        <=>
                        $rightRank;
                }

                $leftClosed =
                    $this->parseDate(
                        $left[
                            'closed_at'
                        ]
                        ?? null
                    );

                $rightClosed =
                    $this->parseDate(
                        $right[
                            'closed_at'
                        ]
                        ?? null
                    );

                return (
                    $rightClosed
                        ?->getTimestamp()
                    ?? 0
                )
                    <=>
                    (
                        $leftClosed
                            ?->getTimestamp()
                        ?? 0
                    );
            }
        );

        return $deals[0];
    }

    /**
     * @param  array<string, mixed>  $deal
     */
    private function dealRank(
        array $deal
    ): int {
        $closed =
            $this->boolean(
                $deal[
                    'is_closed'
                ]
                ?? false
            );

        $won =
            $this->boolean(
                $deal[
                    'is_closed_won'
                ]
                ?? false
            );

        if (! $closed) {
            return 0;
        }

        if ($won) {
            return 1;
        }

        return 2;
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    private function nextTaskDueAt(
        array $tasks
    ): ?CarbonImmutable {
        $dates = [];

        foreach ($tasks as $task) {
            $date =
                $this->parseDate(
                    $task[
                        'due_at'
                    ]
                    ?? null
                );

            if ($date !== null) {
                $dates[] =
                    $date;
            }
        }

        if ($dates === []) {
            return null;
        }

        usort(
            $dates,
            static fn (
                CarbonImmutable $left,
                CarbonImmutable $right
            ): int => $left->getTimestamp()
                <=>
                $right->getTimestamp()
        );

        return $dates[0];
    }

    /**
     * @return array{
     *     score: int,
     *     priority: string,
     *     source: string,
     *     calculated_at: string
     * }
     */
    private function qualificationSnapshot(
        Company $company
    ): array {
        $result =
            $this
                ->qualification
                ->calculate(
                    $company
                );

        return [
            'score' => $result[
                    'score'
                ],

            'priority' => $result[
                    'priority'
                ],

            'source' => 'lead_qualification_v3',

            'calculated_at' => now()
                ->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validArrays(
        mixed $value
    ): array {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $result[] =
                    $item;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function strings(
        mixed $value
    ): array {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item =
                trim(
                    $item
                );

            if ($item !== '') {
                $result[] =
                    $item;
            }
        }

        return array_values(
            array_unique(
                $result
            )
        );
    }

    /**
     * @param  array<string, mixed>  $deal
     */
    private function dealString(
        array $deal,
        string $key,
    ): ?string {
        $value =
            $deal[
                $key
            ]
            ?? null;

        if (
            ! is_string($value)
            && ! is_int($value)
        ) {
            return null;
        }

        $value =
            trim(
                (string) $value
            );

        return $value !== ''
            ? $value
            : null;
    }

    private function boolean(
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

    private function parseDate(
        mixed $value
    ): ?CarbonImmutable {
        if (
            $value instanceof DateTimeInterface
        ) {
            return CarbonImmutable::instance(
                $value
            );
        }

        if (
            ! is_string($value)
            || trim($value) === ''
        ) {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $value
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function dateString(
        mixed $value
    ): ?string {
        return $this
            ->parseDate(
                $value
            )
            ?->toIso8601String();
    }
}
