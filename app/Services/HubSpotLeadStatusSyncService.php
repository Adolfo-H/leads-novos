<?php

namespace App\Services;

use App\Models\CompanyHubSpotLead;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class HubSpotLeadStatusSyncService
{
    public function __construct(
        private readonly HubSpotLeadStatusResolver $resolver,
        private readonly CommercialActivityRecorder $activityRecorder,
    ) {}

    public function sync(
        CompanyHubSpotLead $lead
    ): CompanyHubSpotLead {
        $previousStatus =
            $this->stringValue(
                $lead->work_status
            );

        $previousStage =
            $this->stringValue(
                $lead->deal_stage_id
            );

        $previousOpenTasks =
            (int) $lead->open_task_count;

        $previousDueAt =
            $this->dateValue(
                $lead->last_task_due_at
            )
                ?->toIso8601String();

        $companyId =
            trim(
                (string)
                    $lead->hubspot_company_id
            );

        $dealId =
            trim(
                (string)
                    $lead->hubspot_deal_id
            );

        if (
            $companyId === ''
            || $dealId === ''
        ) {
            throw new RuntimeException(
                'Lead ainda não possui empresa e negócio no HubSpot.'
            );
        }

        $company =
            $this->readObject(
                type: 'companies',
                id: $companyId,
                properties: [
                    'num_contacted_notes',
                    'notes_last_contacted',
                    'notes_last_updated',
                ],
            );

        $deal =
            $this->readObject(
                type: 'deals',
                id: $dealId,
                properties: [
                    'dealstage',
                    'num_contacted_notes',
                    'notes_last_contacted',
                    'notes_last_updated',
                ],
            );

        $dealStage =
            $this->stringValue(
                $deal['dealstage']
                ?? null
            );

        /*
         * A mesma atividade pode aparecer
         * tanto na Company quanto no Deal.
         *
         * Somar os dois contadores duplicaria
         * o mesmo contato comercial.
         */
        $contactedCount =
            max(
                $this->integerValue(
                    $company[
                        'num_contacted_notes'
                    ]
                    ?? null
                ),
                $this->integerValue(
                    $deal[
                        'num_contacted_notes'
                    ]
                    ?? null
                ),
            );

        $activityDates = [
            $this->dateValue(
                $company[
                    'notes_last_contacted'
                ]
                ?? null
            ),

            $this->dateValue(
                $company[
                    'notes_last_updated'
                ]
                ?? null
            ),

            $this->dateValue(
                $deal[
                    'notes_last_contacted'
                ]
                ?? null
            ),

            $this->dateValue(
                $deal[
                    'notes_last_updated'
                ]
                ?? null
            ),
        ];

        $lastActivityAt =
            $this->latestDate(
                $activityDates
            );

        $tasks =
            $this->openTasks(
                companyId: $companyId,
                dealId: $dealId,
            );

        $status =
            $this->resolver->resolve(
                dealStage: $dealStage,

                openTasks: count(
                    $tasks['items']
                ),

                contactedCount: $contactedCount,

                lastActivityAt: $lastActivityAt,
            );

        $workStatusChangedAt =
            $lead->work_status_changed_at;

        if (
            $workStatusChangedAt === null
            || $previousStatus !== $status
        ) {
            $workStatusChangedAt =
                now();
        }

        $activityType =
            match ($status) {
                'converted',
                'discarded',
                'future',
                'refused' => 'deal_stage',

                'waiting' => 'task',

                'contacting' => 'hubspot_activity',

                default => null,
            };

        $rawMetadata =
            $lead->getAttribute(
                'metadata'
            );

        $metadata =
            is_array($rawMetadata)
                ? $rawMetadata
                : [];

        $metadata[
            'hubspot_status'
        ] = [
            'contacted_count' => $contactedCount,

            'deal_stage' => $dealStage,

            'open_tasks' => $tasks['items'],
        ];

        $lead->forceFill([
            'work_status' => $status,

            'work_status_changed_at' => $workStatusChangedAt,

            'deal_stage_id' => $dealStage,

            'last_activity_type' => $activityType,

            'last_activity_at' => $lastActivityAt,

            'open_task_count' => count(
                $tasks['items']
            ),

            'last_task_due_at' => $tasks['next_due_at'],

            'status_synced_at' => now(),

            'sync_error' => null,

            'metadata' => $metadata,
        ])->save();

        $lead =
            $lead->refresh();

        $this
            ->activityRecorder
            ->recordHubSpotChanges(
                lead: $lead,
                previousStatus: $previousStatus,
                previousStage: $previousStage,
                previousOpenTasks: $previousOpenTasks,
                previousDueAt: $previousDueAt,
            );

        return $lead;
    }

    /**
     * @param  list<string>  $properties
     * @return array<string, mixed>
     */
    private function readObject(
        string $type,
        string $id,
        array $properties,
    ): array {
        $response =
            $this->client()
                ->get(
                    $this->baseUrl()
                    .'/crm/v3/objects/'
                    .$type
                    .'/'
                    .rawurlencode($id),
                    [
                        'properties' => implode(
                            ',',
                            $properties
                        ),
                    ]
                );

        $this->ensureSuccess(
            $response,
            'consultar '.$type
        );

        $data =
            $response->json();

        if (! is_array($data)) {
            return [];
        }

        $properties =
            $data['properties']
            ?? [];

        return is_array($properties)
            ? $properties
            : [];
    }

    /**
     * @return array{
     *     items: list<array{
     *         id: string,
     *         subject: string|null,
     *         status: string|null,
     *         due_at: string|null
     *     }>,
     *     next_due_at: CarbonInterface|null
     * }
     */
    private function openTasks(
        string $companyId,
        string $dealId,
    ): array {
        $ids =
            array_values(
                array_unique(
                    array_merge(
                        $this->associationIds(
                            'companies',
                            $companyId,
                            'tasks',
                        ),
                        $this->associationIds(
                            'deals',
                            $dealId,
                            'tasks',
                        ),
                    )
                )
            );

        $items = [];
        $dueDates = [];

        foreach ($ids as $id) {
            $task =
                $this->readObject(
                    type: 'tasks',
                    id: $id,
                    properties: [
                        'hs_task_status',
                        'hs_task_subject',
                        'hs_timestamp',
                    ],
                );

            $status =
                $this->stringValue(
                    $task[
                        'hs_task_status'
                    ]
                    ?? null
                );

            /*
             * Completed deixa de representar
             * uma espera ativa.
             */
            if ($status === 'COMPLETED') {
                continue;
            }

            $dueAt =
                $this->dateValue(
                    $task[
                        'hs_timestamp'
                    ]
                    ?? null
                );

            if ($dueAt !== null) {
                $dueDates[] =
                    $dueAt;
            }

            $items[] = [
                'id' => $id,

                'subject' => $this->stringValue(
                    $task[
                        'hs_task_subject'
                    ]
                    ?? null
                ),

                'status' => $status,

                'due_at' => $dueAt
                    ?->toIso8601String(),
            ];
        }

        usort(
            $dueDates,
            static fn (
                CarbonInterface $a,
                CarbonInterface $b
            ): int => $a->getTimestamp()
                <=>
                $b->getTimestamp()
        );

        return [
            'items' => $items,

            'next_due_at' => $dueDates[0]
                ?? null,
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
                    .rawurlencode($fromId)
                    .'/associations/'
                    .$toType,
                    [
                        'limit' => 100,
                    ]
                );

        $this->ensureSuccess(
            $response,
            'consultar associações'
        );

        $data =
            $response->json();

        if (! is_array($data)) {
            return [];
        }

        $results =
            $data['results']
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
                ?? $result['id']
                ?? null;

            if (is_scalar($id)) {
                $ids[] =
                    (string) $id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<CarbonInterface|null>  $dates
     */
    private function latestDate(
        array $dates
    ): ?CarbonInterface {
        $dates =
            array_values(
                array_filter(
                    $dates,
                    static fn (
                        mixed $date
                    ): bool => $date instanceof CarbonInterface
                )
            );

        if ($dates === []) {
            return null;
        }

        usort(
            $dates,
            static fn (
                CarbonInterface $a,
                CarbonInterface $b
            ): int => $b->getTimestamp()
                <=>
                $a->getTimestamp()
        );

        return $dates[0];
    }

    private function dateValue(
        mixed $value
    ): ?CarbonImmutable {
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

                if (
                    $number
                    > 100000000000
                ) {
                    return CarbonImmutable::createFromTimestampMs(
                        $number
                    );
                }

                return CarbonImmutable::createFromTimestamp(
                    $number
                );
            }

            return CarbonImmutable::parse(
                (string) $value
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function integerValue(
        mixed $value
    ): int {
        return is_numeric($value)
            ? max(
                0,
                (int) $value
            )
            : 0;
    }

    private function stringValue(
        mixed $value
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value =
            trim($value);

        return $value !== ''
            ? $value
            : null;
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
            ->connectTimeout(5)
            ->timeout(30);
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
            .'.'
        );
    }
}
