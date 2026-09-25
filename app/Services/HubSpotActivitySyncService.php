<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyLeadActivity;
use App\Models\HubSpotActivity;
use App\Models\HubSpotCompany;
use App\Models\HubSpotWebhookEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class HubSpotActivitySyncService
{
    public function __construct(
        private readonly HubSpotWebhookObjectTypeService $types,
        private readonly HubSpotWebhookAssociationResolver $resolver,
    ) {}

    /**
     * Sincroniza o objeto de atividade do HubSpot.
     *
     * @param  array<int>  $companyIds
     * @return list<int>
     */
    public function syncEvent(
        HubSpotWebhookEvent $event,
        array $companyIds,
    ): array {
        $type =
            trim(
                (string)
                $event->object_type
            );

        if (
            ! $this
                ->types
                ->isActivity(
                    $type
                )
        ) {
            return $this->companyIds(
                $companyIds
            );
        }

        /*
         * Company fiscal já conhecida por
         * atividades antigas.
         */
        $companyIds =
            array_merge(
                $companyIds,

                $this->localCompanyIds(
                    type: $type,

                    externalId: $event->object_id,
                )
            );

        /*
         * Primeiro aproveitamos vínculos locais
         * já conhecidos.
         *
         * Se a Company fiscal já foi resolvida,
         * não precisamos consultar novamente
         * associações no HubSpot.
         */
        $hubSpotCompanies =
            [];

        if ($companyIds !== []) {
            $hubSpotCompanies =
                HubSpotCompany::query()
                    ->whereIn(
                        'company_id',
                        $companyIds
                    )
                    ->get()
                    ->all();
        } else {
            /*
             * Somente quando ainda não sabemos
             * qual é a Company fiscal consultamos
             * as associações do próprio HubSpot.
             *
             * Esse é o fluxo das empresas em
             * "CRM sem CNPJ".
             */
            $hubSpotCompanyIds =
                $this
                    ->resolver
                    ->hubSpotCompanyIds(
                        objectType: $type,

                        objectId: $event->object_id,
                    );

            foreach (
                $hubSpotCompanyIds as $hubSpotCompanyId
            ) {
                $hubSpotCompany =
                    HubSpotCompany::query()
                        ->firstOrCreate([
                            'hubspot_id' => $hubSpotCompanyId,
                        ]);

                $hubSpotCompanies[] =
                    $hubSpotCompany;

                if (
                    is_numeric(
                        $hubSpotCompany
                            ->company_id
                    )
                ) {
                    $companyIds[] =
                        (int)
                        $hubSpotCompany
                            ->company_id;
                }
            }
        }

        $companyIds =
            $this->companyIds(
                $companyIds
            );

        /*
         * Exclusão:
         *
         * mantemos tombstone no mirror para
         * preservar auditoria e ocultamos a
         * projeção fiscal.
         */
        if (
            $this->isDeletion(
                $event->subscription_type
            )
        ) {
            $this->markDeleted(
                type: $type,

                externalId: $event->object_id,
            );

            return $companyIds;
        }

        $record =
            $this->readActivity(
                type: $type,

                objectId: $event->object_id,
            );

        /*
         * Se desapareceu entre webhook e worker,
         * tratamos como excluído.
         */
        if ($record === null) {
            $this->markDeleted(
                type: $type,

                externalId: $event->object_id,
            );

            return $companyIds;
        }

        $properties =
            $record[
                'properties'
            ];

        $occurredAt =
            $this->occurredAt(
                type: $type,

                properties: $properties,

                fallback: $record[
                        'created_at'
                    ],
            );

        $sourceUpdatedAt =
            $record[
                'updated_at'
            ]
            ?? $occurredAt;

        $title =
            $this->title(
                $type,
                $properties
            );

        $description =
            $this->description(
                $type,
                $properties
            );

        /*
         * Espelho independente de CNPJ.
         */
        $activity =
            HubSpotActivity::query()
                ->updateOrCreate(
                    [
                        'object_type' => $type,

                        'hubspot_id' => $event
                            ->object_id,
                    ],
                    [
                        'title' => mb_strimwidth(
                            $title,
                            0,
                            180,
                            '…'
                        ),

                        'description' => $description,

                        'occurred_at' => $occurredAt,

                        'source_updated_at' => $sourceUpdatedAt,

                        'is_deleted' => false,

                        'raw_properties' => $properties,
                    ]
                );

        $activity
            ->companies()
            ->syncWithoutDetaching(
                array_values(
                    array_unique(
                        array_map(
                            static fn (
                                HubSpotCompany $company
                            ): int => (int)
                                $company->id,

                            $hubSpotCompanies
                        )
                    )
                )
            );

        /*
         * Se alguma dessas HubSpot Companies já
         * estiver identificada fiscalmente,
         * projetamos imediatamente no histórico
         * tradicional.
         */
        foreach (
            $hubSpotCompanies as $hubSpotCompany
        ) {
            if (
                ! is_numeric(
                    $hubSpotCompany
                        ->company_id
                )
            ) {
                continue;
            }

            $companyIds[] =
                (int)
                $hubSpotCompany
                    ->company_id;
        }

        $companyIds =
            $this->companyIds(
                $companyIds
            );

        foreach (
            $companyIds as $companyId
        ) {
            $this->projectActivity(
                activity: $activity,

                companyId: $companyId,

                subscriptionType: $event
                    ->subscription_type,
            );
        }

        return $companyIds;
    }

    /**
     * Projeta atividades previamente recebidas
     * enquanto a HubSpot Company ainda estava
     * sem CNPJ.
     */
    public function promoteForCompany(
        HubSpotCompany $hubSpotCompany,
        Company $company,
    ): int {
        $activities =
            $hubSpotCompany
                ->activities()
                ->get();

        $count = 0;

        foreach (
            $activities as $activity
        ) {
            $this->projectActivity(
                activity: $activity,

                companyId: $company->id,

                subscriptionType: null,
            );

            $count++;
        }

        return $count;
    }

    private function projectActivity(
        HubSpotActivity $activity,
        int $companyId,
        ?string $subscriptionType,
    ): void {
        $properties =
            $activity
                ->getAttribute(
                    'raw_properties'
                );

        CompanyLeadActivity::query()
            ->updateOrCreate(
                [
                    'company_id' => $companyId,

                    'source' => 'hubspot',

                    'source_object_type' => $activity
                        ->object_type,

                    'source_object_id' => $activity
                        ->hubspot_id,
                ],
                [
                    'user_id' => null,

                    'type' => 'hubspot_'
                        .$activity
                            ->object_type,

                    'title' => $activity->title
                        ?? 'Atividade HubSpot',

                    'description' => $activity
                        ->description,

                    'metadata' => [
                        'hubspot_object_type' => $activity
                            ->object_type,

                        'hubspot_object_id' => $activity
                            ->hubspot_id,

                        'subscription_type' => $subscriptionType,

                        'properties' => is_array(
                            $properties
                        )
                                ? $properties
                                : [],
                    ],

                    'occurred_at' => $activity
                        ->occurred_at,

                    'source_updated_at' => $activity
                        ->source_updated_at,

                    'is_deleted' => $activity
                        ->is_deleted,
                ]
            );
    }

    /**
     * @return array{
     *     properties: array<string, mixed>,
     *     created_at: CarbonImmutable|null,
     *     updated_at: CarbonImmutable|null
     * }|null
     */
    private function readActivity(
        string $type,
        string $objectId,
    ): ?array {
        $plural =
            $this
                ->types
                ->apiPlural(
                    $type
                );

        if ($plural === null) {
            return null;
        }

        try {
            $response =
                $this
                    ->client()
                    ->get(
                        $this->baseUrl()
                        .'/crm/v3/objects/'
                        .$plural
                        .'/'
                        .rawurlencode(
                            $objectId
                        ),
                        [
                            'properties' => implode(
                                ',',
                                $this->propertiesFor(
                                    $type
                                )
                            ),
                        ]
                    );
        } catch (
            ConnectionException $exception
        ) {
            throw new RuntimeException(
                'Não foi possível consultar atividade no HubSpot.',
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
                'Resposta inválida ao consultar atividade no HubSpot.'
            );
        }

        $properties =
            $data[
                'properties'
            ]
            ?? [];

        if (! is_array($properties)) {
            $properties = [];
        }

        return [
            'properties' => $properties,

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
    private function propertiesFor(
        string $type
    ): array {
        return match ($type) {
            'call' => [
                'hs_timestamp',
                'hs_call_title',
                'hs_call_body',
                'hs_call_status',
                'hs_call_disposition',
                'hs_call_duration',
            ],

            'note' => [
                'hs_timestamp',
                'hs_note_body',
            ],

            'task' => [
                'hs_timestamp',
                'hs_task_subject',
                'hs_task_body',
                'hs_task_status',
                'hs_task_priority',
                'hs_task_type',
            ],

            'meeting' => [
                'hs_timestamp',
                'hs_meeting_title',
                'hs_meeting_body',
                'hs_meeting_start_time',
                'hs_meeting_end_time',
                'hs_meeting_outcome',
            ],

            'email' => [
                'hs_timestamp',
                'hs_email_subject',
                'hs_email_text',
                'hs_email_html',
                'hs_email_status',
                'hs_email_direction',
            ],

            default => [
                'hs_timestamp',
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function title(
        string $type,
        array $properties,
    ): string {
        $candidate =
            match ($type) {
                'call' => $properties[
                        'hs_call_title'
                    ]
                    ?? null,

                'task' => $properties[
                        'hs_task_subject'
                    ]
                    ?? null,

                'meeting' => $properties[
                        'hs_meeting_title'
                    ]
                    ?? null,

                'email' => $properties[
                        'hs_email_subject'
                    ]
                    ?? null,

                default => null,
            };

        $candidate =
            $this->cleanText(
                $candidate
            );

        if ($candidate !== null) {
            return $candidate;
        }

        return match ($type) {
            'call' => 'Ligação HubSpot',

            'note' => 'Observação HubSpot',

            'task' => 'Tarefa HubSpot',

            'meeting' => 'Reunião HubSpot',

            'email' => 'E-mail HubSpot',

            default => 'Atividade HubSpot',
        };
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function description(
        string $type,
        array $properties,
    ): ?string {
        $body =
            match ($type) {
                'call' => $properties[
                        'hs_call_body'
                    ]
                    ?? null,

                'note' => $properties[
                        'hs_note_body'
                    ]
                    ?? null,

                'task' => $properties[
                        'hs_task_body'
                    ]
                    ?? null,

                'meeting' => $properties[
                        'hs_meeting_body'
                    ]
                    ?? null,

                'email' => $properties[
                        'hs_email_text'
                    ]
                    ?? $properties[
                        'hs_email_html'
                    ]
                    ?? null,

                default => null,
            };

        $body =
            $this->cleanText(
                $body
            );

        $details =
            $this->details(
                $type,
                $properties
            );

        $parts = [];

        if ($body !== null) {
            $parts[] =
                $body;
        }

        if ($details !== []) {
            $parts[] =
                implode(
                    ' · ',
                    $details
                );
        }

        if ($parts === []) {
            return null;
        }

        return mb_strimwidth(
            implode(
                ' | ',
                $parts
            ),
            0,
            6000,
            '…'
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<string>
     */
    private function details(
        string $type,
        array $properties,
    ): array {
        $details = [];

        if ($type === 'call') {
            $this->appendDetail(
                $details,
                'Status',
                $properties[
                    'hs_call_status'
                ]
                ?? null
            );

            $this->appendDetail(
                $details,
                'Resultado',
                $properties[
                    'hs_call_disposition'
                ]
                ?? null
            );

            $duration =
                $this->durationLabel(
                    $properties[
                        'hs_call_duration'
                    ]
                    ?? null
                );

            if ($duration !== null) {
                $details[] =
                    'Duração: '
                    .$duration;
            }
        }

        if ($type === 'task') {
            $this->appendDetail(
                $details,
                'Status',
                $properties[
                    'hs_task_status'
                ]
                ?? null
            );

            $this->appendDetail(
                $details,
                'Prioridade',
                $properties[
                    'hs_task_priority'
                ]
                ?? null
            );

            $this->appendDetail(
                $details,
                'Tipo',
                $properties[
                    'hs_task_type'
                ]
                ?? null
            );
        }

        if ($type === 'meeting') {
            $this->appendDetail(
                $details,
                'Resultado',
                $properties[
                    'hs_meeting_outcome'
                ]
                ?? null
            );
        }

        if ($type === 'email') {
            $this->appendDetail(
                $details,
                'Status',
                $properties[
                    'hs_email_status'
                ]
                ?? null
            );

            $this->appendDetail(
                $details,
                'Direção',
                $properties[
                    'hs_email_direction'
                ]
                ?? null
            );
        }

        return $details;
    }

    /**
     * @param  list<string>  $details
     */
    private function appendDetail(
        array &$details,
        string $label,
        mixed $value,
    ): void {
        $value =
            $this->cleanText(
                $value
            );

        if ($value === null) {
            return;
        }

        $details[] =
            $label
            .': '
            .$value;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function occurredAt(
        string $type,
        array $properties,
        ?CarbonImmutable $fallback,
    ): CarbonImmutable {
        $candidates = [];

        if ($type === 'meeting') {
            $candidates[] =
                $properties[
                    'hs_meeting_start_time'
                ]
                ?? null;
        }

        $candidates[] =
            $properties[
                'hs_timestamp'
            ]
            ?? null;

        foreach ($candidates as $candidate) {
            $date =
                $this->dateValue(
                    $candidate
                );

            if ($date !== null) {
                return $date;
            }
        }

        return $fallback
            ?? CarbonImmutable::now();
    }

    /**
     * @return list<int>
     */
    private function localCompanyIds(
        string $type,
        string $externalId,
    ): array {
        $ids =
            CompanyLeadActivity::query()
                ->where(
                    'source',
                    'hubspot'
                )
                ->where(
                    'source_object_type',
                    $type
                )
                ->where(
                    'source_object_id',
                    $externalId
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
                ->all();

        return array_values(
            $ids
        );
    }

    private function markDeleted(
        string $type,
        string $externalId,
    ): void {
        HubSpotActivity::query()
            ->where(
                'object_type',
                $type
            )
            ->where(
                'hubspot_id',
                $externalId
            )
            ->update([
                'is_deleted' => true,

                'source_updated_at' => now(),

                'updated_at' => now(),
            ]);

        CompanyLeadActivity::query()
            ->where(
                'source',
                'hubspot'
            )
            ->where(
                'source_object_type',
                $type
            )
            ->where(
                'source_object_id',
                $externalId
            )
            ->update([
                'is_deleted' => true,

                'source_updated_at' => now(),

                'updated_at' => now(),
            ]);
    }

    private function isDeletion(
        string $subscriptionType
    ): bool {
        return str_contains(
            mb_strtolower(
                $subscriptionType
            ),
            'deletion'
        );
    }

    private function cleanText(
        mixed $value
    ): ?string {
        if (! is_scalar($value)) {
            return null;
        }

        $value =
            (string) $value;

        $value =
            preg_replace(
                '/<br\s*\/?>/i',
                "\n",
                $value
            )
            ?? $value;

        $value =
            strip_tags(
                $value
            );

        $value =
            html_entity_decode(
                $value,
                ENT_QUOTES
                | ENT_HTML5,
                'UTF-8'
            );

        $value =
            preg_replace(
                '/[ \t]+/u',
                ' ',
                $value
            )
            ?? $value;

        $value =
            preg_replace(
                '/\n{3,}/u',
                "\n\n",
                $value
            )
            ?? $value;

        $value =
            trim(
                $value
            );

        return $value !== ''
            ? $value
            : null;
    }

    private function durationLabel(
        mixed $value
    ): ?string {
        if (! is_numeric($value)) {
            return null;
        }

        $milliseconds =
            max(
                0,
                (int) $value
            );

        $seconds =
            (int) floor(
                $milliseconds
                / 1000
            );

        $minutes =
            intdiv(
                $seconds,
                60
            );

        $remaining =
            $seconds % 60;

        if ($minutes > 0) {
            return
                $minutes
                .'m '
                .$remaining
                .'s';
        }

        return
            $remaining
            .'s';
    }

    private function dateValue(
        mixed $value
    ): ?CarbonImmutable {
        $timezone =
            (string) config(
                'app.timezone',
                'UTC'
            );

        if (
            $value instanceof CarbonInterface
        ) {
            return CarbonImmutable::instance(
                $value
            )->setTimezone(
                $timezone
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

                $date =
                    $number
                    > 100000000000
                        ? CarbonImmutable::createFromTimestampMs(
                            $number
                        )
                        : CarbonImmutable::createFromTimestamp(
                            $number
                        );

                return $date->setTimezone(
                    $timezone
                );
            }

            if (is_scalar($value)) {
                return CarbonImmutable::parse(
                    (string) $value
                )->setTimezone(
                    $timezone
                );
            }
        } catch (Throwable) {
            return null;
        }

        return null;
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
                'Token sem permissão para consultar atividades do HubSpot.'
            );
        }

        if ($response->status() === 429) {
            throw new RuntimeException(
                'Limite de API do HubSpot atingido.'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Falha ao consultar atividade do HubSpot. HTTP '
                .$response->status()
                .'.'
            );
        }
    }

    /**
     * @param  array<int>  $ids
     * @return list<int>
     */
    private function companyIds(
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
