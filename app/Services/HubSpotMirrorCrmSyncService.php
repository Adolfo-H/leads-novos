<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyCrmCheck;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class HubSpotMirrorCrmSyncService
{
    public function __construct(
        private readonly SdrScoringService $sdr,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(
        Company $company
    ): array {
        $records =
            HubSpotCompany::query()
                ->trustedFiscalLink()
                ->where(
                    'company_id',
                    $company->id
                )
                ->with([
                    'commercialDeals',
                    'tasks',
                    'contacts',
                ])
                ->get();

        if ($records->isEmpty()) {
            return [
                'status' => 'not_found',

                'external_id' => null,

                'external_name' => null,

                'external_domain' => null,

                'lifecycle_stage' => null,

                'contacted_count' => 0,

                'associated_deals_count' => 0,

                'last_contacted_at' => null,

                'external_url' => null,

                'metadata' => [
                    'status_source' => 'hubspot_export_mirror',

                    'crm_checked' => true,

                    'deals' => [],

                    'deal_summary' => $this->dealSummary(
                        []
                    ),

                    'hubspot_company_ids' => [],
                ],
            ];
        }

        /*
         * NEGÓCIOS:
         *
         * Mais de um registro Company do
         * HubSpot pode apontar para a mesma
         * empresa fiscal.
         *
         * Por isso agregamos e deduplicamos
         * os negócios pelo HubSpot Deal ID.
         */
        $dealsById = [];

        foreach ($records as $record) {
            foreach (
                $record->commercialDeals as $deal
            ) {
                $dealId =
                    trim(
                        (string)
                        $deal->hubspot_id
                    );

                if ($dealId === '') {
                    continue;
                }

                $dealsById[
                    $dealId
                ] =
                    $this->dealData(
                        $deal
                    );
            }
        }

        $deals =
            array_values(
                $dealsById
            );

        /*
         * TAREFAS abertas.
         */
        $tasksById = [];

        foreach ($records as $record) {
            foreach (
                $record->tasks as $task
            ) {
                if (
                    ! (bool)
                    $task->getAttribute(
                        'is_open'
                    )
                ) {
                    continue;
                }

                $taskId =
                    trim(
                        (string)
                        $task->hubspot_id
                    );

                if ($taskId === '') {
                    continue;
                }

                $tasksById[
                    $taskId
                ] = [
                    'id' => $taskId,

                    'title' => $task->title,

                    'status' => $task->status,

                    'type' => $task->type,

                    'assigned_to' => $task->assigned_to,

                    'due_at' => $this->dateString(
                        $task->getAttribute(
                            'due_at'
                        )
                    ),
                ];
            }
        }

        $tasks =
            array_values(
                $tasksById
            );

        /*
         * CONTATOS associados aos registros HubSpot.
         *
         * O mesmo contato pode estar associado a
         * mais de um registro Company.
         */
        $contactsById = [];

        foreach ($records as $record) {
            foreach (
                $record->contacts as $contact
            ) {
                $contactId =
                    trim(
                        (string) $contact->hubspot_id
                    );

                if ($contactId === '') {
                    continue;
                }

                $name =
                    trim(
                        implode(
                            ' ',
                            array_filter([
                                trim(
                                    (string) $contact->first_name
                                ),

                                trim(
                                    (string) $contact->last_name
                                ),
                            ])
                        )
                    );

                $contactsById[
                    $contactId
                ] = [
                    'id' => $contactId,

                    'name' => $name !== ''
                            ? $name
                            : null,

                    'email' => $contact->email,

                    'phone' => $contact->phone,

                    'mobile_phone' => $contact->mobile_phone,

                    'job_title' => $contact->job_title,

                    'company_name' => $contact->company_name,

                    'owner_name' => $contact->owner_name,
                ];
            }
        }

        $contacts =
            array_values(
                $contactsById
            );

        /*
         * CONTATOS REALIZADOS / ÚLTIMA ATIVIDADE.
         */
        $contactedCount = 0;

        $lastContactedAt =
            null;

        $ownerNames = [];

        $companyRecords = [];

        foreach ($records as $record) {
            $raw =
                $record->getAttribute(
                    'raw_properties'
                );

            $properties =
                is_array($raw)
                    ? $raw
                    : [];

            $contactedCount +=
                $this->integerValue(
                    $properties[
                        'Número de contatos efetuados'
                    ]
                    ?? 0
                );

            $candidate =
                $this->parseDate(
                    $properties[
                        'Último contato'
                    ]
                    ?? null
                );

            $candidate ??=
                $this->parseDate(
                    $record->getAttribute(
                        'last_activity_at'
                    )
                );

            if (
                $candidate !== null
                && (
                    $lastContactedAt === null
                    || $candidate
                        ->greaterThan(
                            $lastContactedAt
                        )
                )
            ) {
                $lastContactedAt =
                    $candidate;
            }

            $owner =
                trim(
                    (string)
                    $record->owner_name
                );

            if ($owner !== '') {
                $ownerNames[] =
                    $owner;
            }

            $companyRecords[] = [
                'id' => (string)
                    $record->hubspot_id,

                'name' => $record->name,

                'domain' => $record->domain,

                'lifecycle_stage' => $record
                    ->lifecycle_stage,

                'owner_name' => $record->owner_name,

                'last_activity_at' => $this->dateString(
                    $record->getAttribute(
                        'last_activity_at'
                    )
                ),

                'deals' => $record
                    ->commercialDeals
                    ->count(),
            ];
        }

        foreach ($deals as $deal) {
            $owner =
                trim(
                    (string) (
                        $deal[
                            'owner_name'
                        ]
                        ?? ''
                    )
                );

            if ($owner !== '') {
                $ownerNames[] =
                    $owner;
            }
        }

        foreach ($tasks as $task) {
            $owner =
                trim(
                    (string) (
                        $task[
                            'assigned_to'
                        ]
                        ?? ''
                    )
                );

            if ($owner !== '') {
                $ownerNames[] =
                    $owner;
            }
        }

        $ownerNames =
            array_values(
                array_unique(
                    $ownerNames
                )
            );

        /*
         * Registro principal apenas para os
         * campos escalares de CompanyCrmCheck.
         *
         * Priorizamos:
         * 1. negócio aberto;
         * 2. maior quantidade de negócios;
         * 3. atividade mais recente.
         */
        $ordered =
            $records
                ->sort(
                    function (
                        HubSpotCompany $left,
                        HubSpotCompany $right
                    ): int {
                        $leftOpen =
                            $left
                                ->commercialDeals
                                ->filter(
                                    static fn (
                                        HubSpotDeal $deal
                                    ): bool => ! (bool)
                                        $deal->getAttribute(
                                            'is_closed'
                                        )
                                )
                                ->count();

                        $rightOpen =
                            $right
                                ->commercialDeals
                                ->filter(
                                    static fn (
                                        HubSpotDeal $deal
                                    ): bool => ! (bool)
                                        $deal->getAttribute(
                                            'is_closed'
                                        )
                                )
                                ->count();

                        $compare =
                            $rightOpen
                            <=>
                            $leftOpen;

                        if ($compare !== 0) {
                            return $compare;
                        }

                        $compare =
                            $right
                                ->commercialDeals
                                ->count()
                            <=>
                            $left
                                ->commercialDeals
                                ->count();

                        if ($compare !== 0) {
                            return $compare;
                        }

                        $leftDate =
                            $this->parseDate(
                                $left->getAttribute(
                                    'last_activity_at'
                                )
                            );

                        $rightDate =
                            $this->parseDate(
                                $right->getAttribute(
                                    'last_activity_at'
                                )
                            );

                        return
                            (
                                $rightDate
                                    ?->getTimestamp()
                                ?? 0
                            )
                            <=>
                            (
                                $leftDate
                                    ?->getTimestamp()
                                ?? 0
                            );
                    }
                )
                ->values();

        /** @var HubSpotCompany $primary */
        $primary =
            $ordered->first();

        $status =
            $this->status(
                $deals,
                $contactedCount,
                $lastContactedAt,
            );

        $summary =
            $this->dealSummary(
                $deals
            );

        return [
            'status' => $status,

            'external_id' => (string)
                $primary->hubspot_id,

            'external_name' => $primary->name,

            'external_domain' => $primary->domain,

            'lifecycle_stage' => $primary
                ->lifecycle_stage,

            /*
             * O export trouxe o nome do
             * proprietário, não necessariamente
             * o ID interno. Mantemos isso em
             * metadata.
             */
            'owner_external_id' => null,

            'contacted_count' => $contactedCount,

            'associated_deals_count' => count(
                $deals
            ),

            'last_contacted_at' => $lastContactedAt,

            'matched_by' => 'cnpj_root',

            'matched_value' => $company->cnpj_root,

            'external_url' => $this->recordUrl(
                (string)
                $primary->hubspot_id
            ),

            'metadata' => [
                'status_source' => 'hubspot_export_mirror',

                'crm_checked' => true,

                'crm_reported_status' => $status,

                'hubspot_lifecycle_stage' => $primary
                    ->lifecycle_stage,

                'lifecycle_used_for_status' => false,

                /*
             * Mantemos o formato usado
             * atualmente pela tela Leads.
             */
                'deals' => $deals,

                'deal_summary' => $summary,

                'hubspot_company_ids' => $records
                    ->pluck(
                        'hubspot_id'
                    )
                    ->map(
                        static fn (
                            mixed $id
                        ): string => (string) $id
                    )
                    ->values()
                    ->all(),

                'hubspot_company_count' => $records->count(),

                'hubspot_company_records' => $companyRecords,

                'owner_names' => $ownerNames,

                'contacts' => $contacts,

                'contact_count' => count(
                    $contacts
                ),

                'open_tasks' => $tasks,

                'open_task_count' => count(
                    $tasks
                ),

                'source' => 'hubspot_export_2026_09_21',
            ],
        ];
    }

    public function sync(
        Company $company
    ): CompanyCrmCheck {
        $result =
            $this->preview(
                $company
            );

        $check =
            CompanyCrmCheck::query()
                ->updateOrCreate(
                    [
                        'company_id' => $company->id,
                    ],
                    [
                        'provider' => 'hubspot',

                        'status' => $result[
                                'status'
                            ],

                        'external_id' => $result[
                                'external_id'
                            ],

                        'external_name' => $result[
                                'external_name'
                            ],

                        'external_domain' => $result[
                                'external_domain'
                            ],

                        'lifecycle_stage' => $result[
                                'lifecycle_stage'
                            ],

                        'owner_external_id' => $result[
                                'owner_external_id'
                            ]
                            ?? null,

                        'contacted_count' => $result[
                                'contacted_count'
                            ],

                        'associated_deals_count' => $result[
                                'associated_deals_count'
                            ],

                        'last_contacted_at' => $result[
                                'last_contacted_at'
                            ],

                        'matched_by' => $result[
                                'matched_by'
                            ]
                            ?? null,

                        'matched_value' => $result[
                                'matched_value'
                            ]
                            ?? null,

                        'external_url' => $result[
                                'external_url'
                            ],

                        'metadata' => $result[
                                'metadata'
                            ],

                        'checked_at' => now(),
                    ]
                );

        /*
         * O CRM mudou.
         *
         * Recalculamos SDR para:
         * cliente / oportunidade / prospectado /
         * conhecido.
         */
        $company->unsetRelation(
            'crmCheck'
        );

        $company->unsetRelation(
            'sdrScore'
        );

        $this->sdr->recalculate(
            $company
        );

        return $check->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $deals
     */
    private function status(
        array $deals,
        int $contactedCount,
        ?CarbonImmutable $lastContactedAt,
    ): string {
        foreach ($deals as $deal) {
            if (
                ($deal[
                    'is_closed_won'
                ] ?? false) === true
            ) {
                return 'client';
            }
        }

        foreach ($deals as $deal) {
            if (
                ($deal[
                    'is_closed'
                ] ?? false) === false
            ) {
                return 'opportunity';
            }
        }

        if (
            $deals !== []
            || $contactedCount > 0
            || $lastContactedAt !== null
        ) {
            return 'prospected';
        }

        return 'known';
    }

    /**
     * @param  list<array<string, mixed>>  $deals
     * @return array{
     *     total: int,
     *     active: int,
     *     won: int,
     *     closed_lost: int,
     *     stages: list<string>
     * }
     */
    private function dealSummary(
        array $deals
    ): array {
        $active = 0;
        $won = 0;
        $closedLost = 0;
        $stages = [];

        foreach ($deals as $deal) {
            if (
                ($deal[
                    'is_closed_won'
                ] ?? false) === true
            ) {
                $won++;
            } elseif (
                ($deal[
                    'is_closed'
                ] ?? false) === true
            ) {
                $closedLost++;
            } else {
                $active++;
            }

            $stage =
                $deal[
                    'stage_label'
                ]
                ?? null;

            if (
                is_string($stage)
                && trim($stage) !== ''
            ) {
                $stages[] =
                    trim(
                        $stage
                    );
            }
        }

        return [
            'total' => count(
                $deals
            ),

            'active' => $active,

            'won' => $won,

            'closed_lost' => $closedLost,

            'stages' => array_values(
                array_unique(
                    $stages
                )
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dealData(
        HubSpotDeal $deal
    ): array {
        return [
            'id' => (string)
                $deal->hubspot_id,

            'name' => $deal->name,

            'stage_id' => $deal->stage_id,

            'stage_label' => $deal->stage_label,

            'pipeline_id' => $deal->pipeline_id,

            'pipeline_label' => $deal->pipeline_label,

            'owner_name' => $deal->owner_name,

            'is_closed' => (bool)
                $deal->getAttribute(
                    'is_closed'
                ),

            'is_closed_won' => (bool)
                $deal->getAttribute(
                    'is_closed_won'
                ),

            'closed_at' => $this->dateString(
                $deal->getAttribute(
                    'closed_at'
                )
            ),
        ];
    }

    private function integerValue(
        mixed $value
    ): int {
        return is_numeric(
            $value
        )
            ? max(
                0,
                (int) round(
                    (float) $value
                )
            )
            : 0;
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

    private function recordUrl(
        string $hubSpotId
    ): ?string {
        $portalId =
            trim(
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
                $hubSpotId
            ),
        );
    }
}
