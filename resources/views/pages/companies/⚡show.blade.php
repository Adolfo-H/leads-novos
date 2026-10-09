<?php

use App\Contracts\ExportResearchProvider;
use App\Models\Company;
use App\Models\User;
use App\Services\CrmReprospectingPolicyService;
use App\Services\EstablishmentService;
use App\Services\ExportResearchEligibilityService;
use App\Services\ExportResearchQueueService;
use App\Services\HubSpotLeadEligibilityService;
use App\Services\HubSpotManualOpportunityQueueService;
use App\Services\SdrScoringService;
use App\Support\Cnpj;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Company $company;

    public string $newCnaeCode = '';

    public string $newCnaeDescription = '';

    public bool $newCnaePrimary = false;

    public bool $showCnaeForm = false;

    public bool $showHubSpotOpportunityProgress = false;

    public function mount(Company $company): void
    {
        Gate::authorize(
            'view',
            $company,
        );

        $this->company = $company->load([
            'establishments.cnaes',
            'icpScore',
            'crmCheck',
            'exportIntelligence',
            'exportEvidence',
            'sdrScore',
            'hubSpotLead',
            'leadActivities.user',
        ]);

        app(
            SdrScoringService::class
        )->recalculate(
            $this->company
        );

        $this->company->load(
            'sdrScore'
        );
    }

    #[Computed]
    public function matrix()
    {
        return $this->company
            ->establishments
            ->firstWhere('type', 'matrix')
            ?? $this->company
                ->establishments
                ->first();
    }

    #[Computed]
    public function contactEstablishments(): Collection
    {
        return $this->company
            ->establishments
            ->filter(
                fn ($establishment): bool => trim(
                    (string) $establishment->email
                ) !== ''
                    || trim(
                        (string) $establishment->phone_1
                    ) !== ''
                    || trim(
                        (string) $establishment->phone_2
                    ) !== ''
            )
            ->sortBy(
                function ($establishment): string {
                    $typeOrder =
                        $establishment->type === 'matrix'
                            ? '0'
                            : '1';

                    $statusOrder =
                        $establishment->registration_status
                            === 'ATIVA'
                            ? '0'
                            : '1';

                    return implode(
                        '-',
                        [
                            $typeOrder,
                            $statusOrder,
                            (string) (
                                $establishment->order_number
                                ?? '9999'
                            ),
                        ]
                    );
                }
            )
            ->values();
    }

    public function formatEstablishmentDate(
        mixed $value
    ): string {
        if ($value === null) {
            return '—';
        }

        try {
            if (
                $value instanceof DateTimeInterface
            ) {
                return $value->format(
                    'd/m/Y'
                );
            }

            return CarbonImmutable::parse(
                (string) $value
            )->format(
                'd/m/Y'
            );
        } catch (Throwable) {
            return '—';
        }
    }

    public function establishmentAddress(
        $establishment
    ): string {
        $streetParts = array_filter(
            [
                trim(
                    (string)
                        $establishment
                            ->address_type
                ),
                trim(
                    (string)
                        $establishment
                            ->street
                ),
            ],
            fn ($value): bool => $value !== ''
        );

        $street =
            implode(
                ' ',
                $streetParts
            );

        $number =
            trim(
                (string)
                    $establishment
                        ->number
            );

        if (
            $street !== ''
            && $number !== ''
        ) {
            $street .=
                ', '
                .$number;
        }

        $complement =
            trim(
                (string)
                    $establishment
                        ->complement
            );

        if ($complement !== '') {
            $street .=
                $street !== ''
                    ? ' · '.$complement
                    : $complement;
        }

        $neighborhood =
            trim(
                (string)
                    $establishment
                        ->neighborhood
            );

        $city =
            trim(
                (string)
                    $establishment
                        ->municipality_name
            );

        $state =
            trim(
                (string)
                    $establishment
                        ->state
            );

        $zipCode =
            trim(
                (string)
                    $establishment
                        ->zip_code
            );

        $parts = [];

        if ($street !== '') {
            $parts[] = $street;
        }

        if ($neighborhood !== '') {
            $parts[] = $neighborhood;
        }

        if ($city !== '') {
            $location = $city;

            if ($state !== '') {
                $location .=
                    '/'.$state;
            }

            $parts[] = $location;
        } elseif ($state !== '') {
            $parts[] = $state;
        }

        if ($zipCode !== '') {
            $digits =
                preg_replace(
                    '/\D/',
                    '',
                    $zipCode
                );

            if (
                $digits !== null
                && strlen($digits) === 8
            ) {
                $zipCode =
                    substr($digits, 0, 5)
                    .'-'
                    .substr($digits, 5);
            }

            $parts[] =
                'CEP '.$zipCode;
        }

        return $parts !== []
            ? implode(
                ' · ',
                $parts
            )
            : '—';
    }

    /**
     * @return array{
     *     visible: bool,
     *     enabled: bool,
     *     status: string,
     *     label: string,
     *     message: string,
     *     active: bool,
     *     can_queue: bool,
     *     error: string|null,
     *     owner_email: string|null,
     *     progress: int,
     *     progress_step: string,
     *     progress_message: string|null
     * }
     */
    #[Computed]
    public function hubSpotManualOpportunityState(): array
    {
        $this->company->loadMissing([
            'crmCheck',
            'hubSpotLead',
        ]);

        $sync =
            $this->company
                ->hubSpotLead;

        $crm =
            $this->company
                ->crmCheck;

        $rawMetadata =
            $sync?->getAttribute(
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

        $enabled =
            (bool) config(
                'services.hubspot.manual_opportunity_enabled',
                false
            );

        $storedStatus =
            trim(
                (string) (
                    $manual[
                        'status'
                    ]
                    ?? ''
                )
            );

        /*
         * O status persistido pelo Job tem
         * prioridade.
         *
         * synced_at pode ser preenchido quando
         * os objetos remotos já foram criados,
         * mas ainda podem faltar:
         *
         * - refresh CRM;
         * - etapa;
         * - tarefas;
         * - responsável local.
         */
        if (
            in_array(
                $storedStatus,
                [
                    'queued',
                    'processing',
                    'retrying',
                    'failed',
                    'completed',
                ],
                true
            )
        ) {
            $status =
                $storedStatus;

        } elseif (
            $sync !== null
            && $sync->synced_at !== null
        ) {
            $status =
                'completed';

        } elseif (
            $sync !== null
            && (
                $sync->hubspot_company_id !== null
                || $sync->hubspot_deal_id !== null
            )
        ) {
            $status =
                'partial';

        } else {
            $status =
                'available';
        }

        $rawProgress =
            $manual[
                'progress'
            ]
            ?? null;

        $progress =
            is_numeric(
                $rawProgress
            )
                ? (int) $rawProgress
                : 0;

        /*
         * Fallback baseado nos IDs realmente
         * persistidos.
         *
         * Assim a barra continua avançando
         * mesmo durante chamadas demoradas
         * ao HubSpot.
         */
        if ($status === 'queued') {
            $progress =
                max(
                    $progress,
                    5
                );
        }

        if (
            in_array(
                $status,
                [
                    'processing',
                    'retrying',
                ],
                true
            )
        ) {
            $progress =
                max(
                    $progress,
                    10
                );
        }

        if (
            $sync?->hubspot_company_id
            !== null
        ) {
            $progress =
                max(
                    $progress,
                    30
                );
        }

        if (
            $sync?->hubspot_contact_id
            !== null
        ) {
            $progress =
                max(
                    $progress,
                    50
                );
        }

        if (
            $sync?->hubspot_deal_id
            !== null
        ) {
            $progress =
                max(
                    $progress,
                    70
                );
        }

        if (
            $sync?->hubspot_task_id
            !== null
        ) {
            $progress =
                max(
                    $progress,
                    85
                );
        }

        if ($status === 'completed') {
            $progress =
                100;
        }

        $progress =
            max(
                0,
                min(
                    100,
                    $progress
                )
            );

        $progressStep =
            trim(
                (string) (
                    $manual[
                        'step'
                    ]
                    ?? ''
                )
            );

        $rawProgressMessage =
            $manual[
                'progress_message'
            ]
            ?? null;

        $progressMessage =
            is_scalar(
                $rawProgressMessage
            )
            && trim(
                (string)
                    $rawProgressMessage
            ) !== ''
                ? trim(
                    (string)
                        $rawProgressMessage
                )
                : null;

        $active =
            in_array(
                $status,
                [
                    'queued',
                    'processing',
                    'retrying',
                ],
                true
            );

        $evaluation =
            app(
                HubSpotLeadEligibilityService::class
            )->evaluateManual(
                $this->company
            );

        $canQueue =
            $enabled
            && ! $active
            && $status !== 'completed'
            && $evaluation[
                'eligible'
            ];

        $label =
            match ($status) {
                'queued' => 'Na fila',

                'processing' => 'Criando no HubSpot',

                'retrying' => 'Tentando novamente',

                'failed' => 'Falha na criação',

                'completed' => 'Oportunidade criada',

                'partial' => 'Criação incompleta',

                default => 'Disponível',
            };

        $message =
            match ($status) {
                'queued' => 'Solicitação enviada para processamento.',

                'processing' => 'Criando e sincronizando a oportunidade.',

                'retrying' => 'O HubSpot apresentou uma falha temporária. '
                    .'O sistema tentará novamente.',

                'failed' => 'Não foi possível concluir a criação.',

                'completed' => 'Empresa, negócio, tarefa e CRM sincronizados.',

                'partial' => 'Parte dos registros já foi criada. '
                    .'Você pode retomar com segurança.',

                default => 'Crie a empresa, contatos, negócio e tarefa '
                    .'de primeiro contato no HubSpot.',
            };

        $rawError =
            $manual[
                'last_error'
            ]
            ?? $sync?->sync_error;

        $error =
            is_scalar(
                $rawError
            )
            && trim(
                (string) $rawError
            ) !== ''
                ? trim(
                    (string) $rawError
                )
                : null;

        $rawOwnerEmail =
            $manual[
                'initiated_by_email'
            ]
            ?? null;

        $ownerEmail =
            is_scalar(
                $rawOwnerEmail
            )
            && trim(
                (string)
                    $rawOwnerEmail
            ) !== ''
                ? trim(
                    (string)
                        $rawOwnerEmail
                )
                : null;

        $hasManualState =
            $manual !== [];

        $visible =
            $hasManualState
            || (
                $enabled
                && $crm?->status
                    === 'not_found'
            )
            || (
                $sync !== null
                && $sync->synced_at !== null
            );

        return [
            'visible' => $visible,

            'enabled' => $enabled,

            'status' => $status,

            'label' => $label,

            'message' => $message,

            'active' => $active,

            'can_queue' => $canQueue,

            'error' => $error,

            'owner_email' => $ownerEmail,

            'progress' => $progress,

            'progress_step' => $progressStep,

            'progress_message' => $progressMessage,
        ];
    }

    public function createHubSpotOpportunity(): void
    {
        Gate::authorize(
            'createHubSpotOpportunity',
            $this->company,
        );

        $user =
            request()
                ->user();

        if (! $user instanceof User) {
            abort(
                403
            );
        }

        $this->resetErrorBag(
            'hubSpotOpportunity'
        );

        $this->showHubSpotOpportunityProgress =
            true;

        try {
            app(
                HubSpotManualOpportunityQueueService::class
            )->enqueue(
                company: $this->company,
                actor: $user,
            );

            $this->refreshHubSpotOpportunity();

        } catch (Throwable $exception) {
            $this->refreshHubSpotOpportunity();

            $this->addError(
                'hubSpotOpportunity',
                $exception
                    ->getMessage()
            );
        }
    }

    public function closeHubSpotOpportunityProgress(): void
    {
        $state =
            $this
                ->hubSpotManualOpportunityState;

        /*
         * Não permitimos esconder o andamento
         * enquanto o processo está ativo.
         */
        if (
            $state[
                'active'
            ]
        ) {
            return;
        }

        $this->showHubSpotOpportunityProgress =
            false;
    }

    public function refreshHubSpotOpportunity(): void
    {
        $this->company
            ->unsetRelation(
                'hubSpotLead'
            );

        $this->company
            ->unsetRelation(
                'crmCheck'
            );

        $this->company->load([
            'hubSpotLead',
            'crmCheck',
        ]);

        /*
         * O estado é #[Computed].
         *
         * Sem invalidar explicitamente,
         * a mesma requisição pode continuar
         * exibindo o snapshot anterior e o
         * usuário só percebe após F5.
         */
        unset(
            $this
                ->hubSpotManualOpportunityState
        );

        /*
         * O job roda em background.
         *
         * Se o polling descobrir que a
         * oportunidade entrou em fila ou
         * processamento, abrimos o modal
         * automaticamente.
         *
         * Isso elimina a necessidade de F5.
         */
        $state =
            $this
                ->hubSpotManualOpportunityState;

        if (
            $state[
                'active'
            ]
        ) {
            $this->showHubSpotOpportunityProgress =
                true;
        }

    }

    public function hubSpotCompanyUrl(): ?string
    {
        $this->company->loadMissing([
            'hubSpotLead',
            'crmCheck',
        ]);

        $portalId =
            trim(
                (string) config(
                    'services.hubspot.portal_id'
                )
            );

        $companyId =
            trim(
                (string) (
                    $this->company
                        ->hubSpotLead
                        ?->hubspot_company_id
                    ?? $this->company
                        ->crmCheck
                        ?->external_id
                    ?? ''
                )
            );

        if (
            $portalId !== ''
            && $companyId !== ''
        ) {
            return sprintf(
                'https://app.hubspot.com/contacts/%s/record/0-2/%s',
                rawurlencode(
                    $portalId
                ),
                rawurlencode(
                    $companyId
                ),
            );
        }

        $externalUrl =
            trim(
                (string) (
                    $this->company
                        ->crmCheck
                        ?->external_url
                    ?? ''
                )
            );

        if (
            $externalUrl !== ''
            && filter_var(
                $externalUrl,
                FILTER_VALIDATE_URL
            ) !== false
        ) {
            return $externalUrl;
        }

        return null;
    }

    public function hubSpotDealUrl(): ?string
    {
        $this->company->loadMissing(
            'hubSpotLead'
        );

        $portalId =
            trim(
                (string) config(
                    'services.hubspot.portal_id'
                )
            );

        $dealId =
            trim(
                (string) (
                    $this->company
                        ->hubSpotLead
                        ?->hubspot_deal_id
                    ?? ''
                )
            );

        if (
            $portalId === ''
            || $dealId === ''
        ) {
            return null;
        }

        return sprintf(
            'https://app.hubspot.com/contacts/%s/record/0-3/%s',
            rawurlencode(
                $portalId
            ),
            rawurlencode(
                $dealId
            ),
        );
    }

    public function formatPhone(
        ?string $value
    ): string {
        if (
            $value === null
            || trim($value) === ''
        ) {
            return '—';
        }

        $digits =
            preg_replace(
                '/\D/',
                '',
                $value
            );

        if ($digits === null) {
            return $value;
        }

        if (strlen($digits) === 11) {
            return sprintf(
                '(%s) %s-%s',
                substr($digits, 0, 2),
                substr($digits, 2, 5),
                substr($digits, 7, 4),
            );
        }

        if (strlen($digits) === 10) {
            return sprintf(
                '(%s) %s-%s',
                substr($digits, 0, 2),
                substr($digits, 2, 4),
                substr($digits, 6, 4),
            );
        }

        return $value;
    }

    public function phoneHref(
        ?string $value
    ): ?string {
        if (
            $value === null
            || trim($value) === ''
        ) {
            return null;
        }

        $digits =
            preg_replace(
                '/\D/',
                '',
                $value
            );

        return $digits !== null
            && $digits !== ''
                ? 'tel:+55'.$digits
                : null;
    }

    /**
     * @return array{
     *     units_with_contact: int,
     *     emails: list<array{
     *         value: string,
     *         count: int,
     *         locations: list<string>
     *     }>,
     *     phones: list<array{
     *         value: string,
     *         raw: string,
     *         href: string,
     *         count: int,
     *         locations: list<string>
     *     }>
     * }
     */
    #[Computed]
    public function groupContactSummary(): array
    {
        /**
         * @var array<string, array{
         *     value: string,
         *     establishments: array<int, string>
         * }>
         */
        $emailMap = [];

        /**
         * @var array<string, array{
         *     value: string,
         *     raw: string,
         *     href: string,
         *     establishments: array<int, string>
         * }>
         */
        $phoneMap = [];

        foreach (
            $this->company
                ->establishments as $establishment
        ) {
            $type =
                $establishment->type
                    === 'matrix'
                    ? 'Matriz'
                    : 'Filial';

            $city =
                trim(
                    (string)
                        $establishment
                            ->municipality_name
                );

            $state =
                trim(
                    (string)
                        $establishment
                            ->state
                );

            $location =
                $type;

            if ($city !== '') {
                $location .=
                    ' · '
                    .$city;

                if ($state !== '') {
                    $location .=
                        '/'
                        .$state;
                }
            }

            $location .=
                ' · '
                .Cnpj::format(
                    $establishment
                        ->cnpj
                );

            /*
             * E-mail:
             * normalizamos para minúsculas
             * para não duplicar endereços
             * iguais com casing diferente.
             */
            $email =
                mb_strtolower(
                    trim(
                        (string)
                            $establishment
                                ->email
                    )
                );

            if ($email !== '') {
                if (
                    ! isset(
                        $emailMap[
                            $email
                        ]
                    )
                ) {
                    $emailMap[
                        $email
                    ] = [
                        'value' => $email,

                        'establishments' => [],
                    ];
                }

                $emailMap[
                    $email
                ][
                    'establishments'
                ][
                    $establishment->id
                ] = $location;
            }

            /*
             * Telefones:
             * deduplicamos pelos dígitos.
             */
            foreach (
                [
                    $establishment->phone_1,
                    $establishment->phone_2,
                ] as $phone
            ) {
                $digits =
                    preg_replace(
                        '/\D/',
                        '',
                        (string) $phone
                    );

                if (
                    $digits === null
                    || $digits === ''
                ) {
                    continue;
                }

                if (
                    ! isset(
                        $phoneMap[
                            $digits
                        ]
                    )
                ) {
                    $phoneMap[
                        $digits
                    ] = [
                        'value' => $this
                            ->formatPhone(
                                $digits
                            ),

                        'raw' => $digits,

                        'href' => 'tel:+55'
                            .$digits,

                        'establishments' => [],
                    ];
                }

                $phoneMap[
                    $digits
                ][
                    'establishments'
                ][
                    $establishment->id
                ] = $location;
            }
        }

        $emails = [];

        foreach (
            $emailMap as $item
        ) {
            $locations =
                array_values(
                    $item[
                        'establishments'
                    ]
                );

            $emails[] = [
                'value' => $item['value'],

                'count' => count(
                    $locations
                ),

                'locations' => $locations,
            ];
        }

        $phones = [];

        foreach (
            $phoneMap as $item
        ) {
            $locations =
                array_values(
                    $item[
                        'establishments'
                    ]
                );

            $phones[] = [
                'value' => $item['value'],

                'raw' => $item['raw'],

                'href' => $item['href'],

                'count' => count(
                    $locations
                ),

                'locations' => $locations,
            ];
        }

        usort(
            $emails,
            static function (
                array $a,
                array $b
            ): int {
                return $b['count']
                    <=> $a['count']
                    ?: strcmp(
                        $a['value'],
                        $b['value']
                    );
            }
        );

        usort(
            $phones,
            static function (
                array $a,
                array $b
            ): int {
                return $b['count']
                    <=> $a['count']
                    ?: strcmp(
                        $a['raw'],
                        $b['raw']
                    );
            }
        );

        return [
            'units_with_contact' => $this
                ->contactEstablishments
                ->count(),

            'emails' => $emails,

            'phones' => $phones,
        ];
    }

    /**
     * @return array{
     *     total: int,
     *     active: int,
     *     inactive: int,
     *     unknown: int,
     *     active_states_count: int,
     *     active_municipalities_count: int,
     *     states: list<array{
     *         state: string,
     *         count: int
     *     }>,
     *     statuses: list<array{
     *         status: string,
     *         count: int
     *     }>
     * }
     */
    #[Computed]
    public function groupOperationalSummary(): array
    {
        $total = 0;
        $active = 0;
        $inactive = 0;
        $unknown = 0;

        /** @var array<string, int> $stateCounts */
        $stateCounts = [];

        /** @var array<string, true> $municipalities */
        $municipalities = [];

        /** @var array<string, int> $statusCounts */
        $statusCounts = [];

        foreach (
            $this->company
                ->establishments as $establishment
        ) {
            $total++;

            $statusCode =
                trim(
                    (string)
                        $establishment
                            ->registration_status_code
                );

            $status =
                mb_strtoupper(
                    trim(
                        (string)
                            $establishment
                                ->registration_status
                    )
                );

            if ($status === '') {
                $status = match ($statusCode) {
                    '01' => 'NULA',
                    '02' => 'ATIVA',
                    '03' => 'SUSPENSA',
                    '04' => 'INAPTA',
                    '08' => 'BAIXADA',
                    default => '',
                };
            }

            if (
                $statusCode === ''
                && $status === ''
            ) {
                $isActive = false;
                $statusLabel =
                    'SEM STATUS';

                $unknown++;
            } else {
                $isActive =
                    $statusCode !== ''
                        ? $statusCode === '02'
                        : $status === 'ATIVA';

                $statusLabel =
                    $status !== ''
                        ? $status
                        : $statusCode;

                if ($isActive) {
                    $active++;
                } else {
                    $inactive++;
                }
            }

            $statusCounts[
                $statusLabel
            ] =
                (
                    $statusCounts[
                        $statusLabel
                    ]
                    ?? 0
                )
                + 1;

            /*
             * Presença geográfica operacional:
             * somente unidades ativas.
             */
            if (! $isActive) {
                continue;
            }

            $state =
                mb_strtoupper(
                    trim(
                        (string)
                            $establishment
                                ->state
                    )
                );

            if ($state !== '') {
                $stateCounts[
                    $state
                ] =
                    (
                        $stateCounts[
                            $state
                        ]
                        ?? 0
                    )
                    + 1;
            }

            $city =
                trim(
                    (string)
                        $establishment
                            ->municipality_name
                );

            if ($city !== '') {
                $municipalityKey =
                    mb_strtoupper(
                        $city
                    )
                    .'|'
                    .$state;

                $municipalities[
                    $municipalityKey
                ] = true;
            }
        }

        arsort(
            $stateCounts
        );

        arsort(
            $statusCounts
        );

        $states = [];

        foreach (
            $stateCounts as $state => $count
        ) {
            $states[] = [
                'state' => $state,

                'count' => $count,
            ];
        }

        $statuses = [];

        foreach (
            $statusCounts as $status => $count
        ) {
            $statuses[] = [
                'status' => $status,

                'count' => $count,
            ];
        }

        return [
            'total' => $total,

            'active' => $active,

            'inactive' => $inactive,

            'unknown' => $unknown,

            'active_states_count' => count(
                $stateCounts
            ),

            'active_municipalities_count' => count(
                $municipalities
            ),

            'states' => $states,

            'statuses' => $statuses,
        ];
    }

    #[Computed]
    public function groupCnaes(): Collection
    {
        $grouped = [];

        foreach (
            $this->company
                ->establishments as $establishment
        ) {
            foreach (
                $establishment->cnaes as $cnae
            ) {
                $code =
                    trim(
                        (string) $cnae->code
                    );

                if ($code === '') {
                    continue;
                }

                if (
                    ! isset(
                        $grouped[$code]
                    )
                ) {
                    $grouped[$code] = [
                        'code' => $code,

                        'description' => $cnae->description,

                        'units' => [],

                        'primary_units' => [],

                        'active_units' => [],
                    ];
                }

                if (
                    empty(
                        $grouped[
                            $code
                        ][
                            'description'
                        ]
                    )
                    && $cnae->description
                ) {
                    $grouped[
                        $code
                    ][
                        'description'
                    ] = $cnae->description;
                }

                $type =
                    $establishment->type
                        === 'matrix'
                        ? 'Matriz'
                        : 'Filial';

                $location =
                    $type;

                if (
                    $establishment
                        ->municipality_name
                ) {
                    $location .=
                        ' · '
                        .$establishment
                            ->municipality_name;

                    if (
                        $establishment->state
                    ) {
                        $location .=
                            '/'
                            .$establishment
                                ->state;
                    }
                }

                $grouped[
                    $code
                ][
                    'units'
                ][
                    $establishment->id
                ] = $location;

                $isPrimary =
                    (bool) data_get(
                        $cnae,
                        'pivot.is_primary',
                        false
                    );

                if ($isPrimary) {
                    $grouped[
                        $code
                    ][
                        'primary_units'
                    ][
                        $establishment->id
                    ] = true;
                }

                $statusCode =
                    trim(
                        (string)
                            $establishment
                                ->registration_status_code
                    );

                $status =
                    mb_strtoupper(
                        trim(
                            (string)
                                $establishment
                                    ->registration_status
                        )
                    );

                $isActive =
                    $statusCode !== ''
                        ? $statusCode === '02'
                        : (
                            $status !== ''
                                ? $status === 'ATIVA'
                                : true
                        );

                if ($isActive) {
                    $grouped[
                        $code
                    ][
                        'active_units'
                    ][
                        $establishment->id
                    ] = true;
                }
            }
        }

        return collect(
            $grouped
        )
            ->map(
                function (
                    array $item
                ): array {
                    $locations =
                        array_values(
                            $item[
                                'units'
                            ]
                        );

                    return [
                        'code' => $item['code'],

                        'description' => $item[
                                'description'
                            ],

                        'units_count' => count(
                            $item[
                                'units'
                            ]
                        ),

                        'active_units_count' => count(
                            $item[
                                'active_units'
                            ]
                        ),

                        'primary_units_count' => count(
                            $item[
                                'primary_units'
                            ]
                        ),

                        'locations' => $locations,
                    ];
                }
            )
            ->sort(
                function (
                    array $a,
                    array $b
                ): int {
                    return
                        $b[
                            'primary_units_count'
                        ]
                        <=>
                        $a[
                            'primary_units_count'
                        ]
                        ?: (
                            $b[
                                'active_units_count'
                            ]
                            <=>
                            $a[
                                'active_units_count'
                            ]
                        )
                        ?: (
                            $b[
                                'units_count'
                            ]
                            <=>
                            $a[
                                'units_count'
                            ]
                        )
                        ?: strcmp(
                            $a['code'],
                            $b['code']
                        );
                }
            )
            ->values();
    }

    #[Computed]
    public function primaryCnae()
    {
        return $this->matrix
            ?->cnaes
            ->first(
                fn ($cnae) => (bool) $cnae
                    ->pivot
                    ->is_primary
            );
    }

    #[Computed]
    public function secondaryCnaes(): Collection
    {
        if (! $this->matrix) {
            return collect();
        }

        return $this->matrix
            ->cnaes
            ->filter(
                fn ($cnae) => ! (bool) $cnae
                    ->pivot
                    ->is_primary
            )
            ->values();
    }

    /**
     * @return array{
     *     eligible: bool,
     *     reason: string,
     *     message: string,
     *     cooldown_days: int,
     *     last_activity_at: string|null,
     *     next_allowed_at: string|null
     * }|null
     */
    #[Computed]
    public function crmReprospecting(): ?array
    {
        $crm =
            $this->company
                ->crmCheck;

        if (
            ! $crm
            || $crm->status
                !== 'prospected'
        ) {
            return null;
        }

        return app(
            CrmReprospectingPolicyService::class
        )->evaluate(
            $crm
        );
    }

    public function formatReprospectingDate(
        ?string $value
    ): string {
        if (
            $value === null
            || trim($value) === ''
        ) {
            return '—';
        }

        try {
            return CarbonImmutable::parse(
                $value
            )->format(
                'd/m/Y'
            );
        } catch (Throwable) {
            return '—';
        }
    }

    public function toggleCnaeForm(): void
    {
        Gate::authorize(
            'manageData',
            $this->company,
        );

        $this->showCnaeForm =
            ! $this->showCnaeForm;

        if (! $this->showCnaeForm) {
            $this->resetCnaeForm();
        }
    }

    public function addCnae(
        EstablishmentService $service
    ): void {
        Gate::authorize(
            'manageData',
            $this->company,
        );

        $validated = $this->validate([
            'newCnaeCode' => [
                'required',
                'string',
                'max:20',
            ],

            'newCnaeDescription' => [
                'nullable',
                'string',
                'max:255',
            ],

            'newCnaePrimary' => [
                'boolean',
            ],
        ]);

        if (! $this->matrix) {
            $this->addError(
                'newCnaeCode',
                'A empresa não possui matriz cadastrada.'
            );

            return;
        }

        try {
            $service->addCnae(
                $this->matrix,
                [
                    'code' => $validated[
                            'newCnaeCode'
                        ],

                    'description' => $validated[
                            'newCnaeDescription'
                        ] ?: null,

                    'is_primary' => $validated[
                            'newCnaePrimary'
                        ],
                ]
            );
        } catch (InvalidArgumentException $exception) {
            $this->addError(
                'newCnaeCode',
                $exception->getMessage()
            );

            return;
        }

        $this->reloadCompany();

        $this->resetCnaeForm();

        $this->showCnaeForm = false;

        session()->flash(
            'success',
            'CNAE adicionado com sucesso.'
        );
    }

    public function makeCnaePrimary(
        int $cnaeId,
        EstablishmentService $service
    ): void {
        Gate::authorize(
            'manageData',
            $this->company,
        );

        if (! $this->matrix) {
            return;
        }

        $cnae = $this->matrix
            ->cnaes
            ->firstWhere(
                'id',
                $cnaeId
            );

        if (! $cnae) {
            return;
        }

        try {
            $service->setPrimaryCnae(
                $this->matrix,
                $cnae
            );
        } catch (InvalidArgumentException) {
            return;
        }

        $this->reloadCompany();

        session()->flash(
            'success',
            'CNAE principal atualizado.'
        );
    }

    public function removeCnae(
        int $cnaeId,
        EstablishmentService $service
    ): void {
        Gate::authorize(
            'manageData',
            $this->company,
        );

        if (! $this->matrix) {
            return;
        }

        $cnae = $this->matrix
            ->cnaes
            ->firstWhere(
                'id',
                $cnaeId
            );

        if (! $cnae) {
            return;
        }

        $service->removeCnae(
            $this->matrix,
            $cnae
        );

        $this->reloadCompany();

        session()->flash(
            'success',
            'CNAE removido.'
        );
    }

    private function resetCnaeForm(): void
    {
        $this->newCnaeCode = '';

        $this->newCnaeDescription = '';

        $this->newCnaePrimary = false;

        $this->resetValidation([
            'newCnaeCode',
            'newCnaeDescription',
            'newCnaePrimary',
        ]);
    }

    private function reloadCompany(): void
    {
        $this->company = $this->company
            ->fresh()
            ->load([
                'establishments.cnaes',
                'icpScore',
                'crmCheck',
                'exportIntelligence',
                'exportEvidence',
                'sdrScore',
            ]);

        unset(
            $this->matrix,
            $this->primaryCnae,
            $this->secondaryCnaes,
            $this->groupCnaes,
            $this->groupOperationalSummary,
            $this->contactEstablishments,
            $this->groupContactSummary,
            $this->crmReprospecting,
            $this->exportResearchSummary,
        );
    }

    /**
     * @return array{
     *     eligible: bool,
     *     reason: string,
     *     message: string
     * }
     */
    #[Computed]
    public function exportResearchEligibility(): array
    {
        return app(
            ExportResearchEligibilityService::class
        )->evaluate(
            $this->company
        );
    }

    public function exportResearchConfigured(): bool
    {
        if (
            ! (bool) config(
                'prospector.export_research.enabled',
                false
            )
        ) {
            return false;
        }

        $provider =
            app(
                ExportResearchProvider::class
            )->name();

        $apiKey =
            match ($provider) {
                'tavily' => config(
                    'services.tavily.api_key'
                ),

                'openai-web-search' => config(
                    'services.openai.api_key'
                ),

                default => null,
            };

        return is_string($apiKey)
            && trim($apiKey) !== '';
    }

    public function exportResearchProviderLabel(): string
    {
        $provider =
            $this->company
                ->exportIntelligence
                ?->research_provider;

        if (
            ! is_string($provider)
            || trim($provider) === ''
        ) {
            $provider =
                app(
                    ExportResearchProvider::class
                )->name();
        }

        return match ($provider) {
            'tavily' => 'Tavily',

            'openai-web-search' => 'OpenAI Web',

            default => ucfirst(
                str_replace(
                    '-',
                    ' ',
                    $provider
                )
            ),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function exportResearchSummary(): ?array
    {
        $summary =
            data_get(
                $this->company
                    ->exportIntelligence
                    ?->metadata,
                'research_summary'
            );

        return is_array(
            $summary
        )
            ? $summary
            : null;
    }

    public function exportResearchRunning(): bool
    {
        $status =
            $this->company
                ->exportIntelligence
                ?->research_status;

        return in_array(
            $status,
            [
                'queued',
                'processing',
            ],
            true
        );
    }

    public function researchExports(
        ExportResearchQueueService $queue
    ): void {
        Gate::authorize(
            'researchExports',
            $this->company,
        );

        if (
            ! $this->exportResearchConfigured()
        ) {
            $this->addError(
                'exportResearch',
                'A pesquisa externa está '
                .'desativada ou sem provider '
                .'configurado.'
            );

            return;
        }

        $eligibility =
            app(
                ExportResearchEligibilityService::class
            )->evaluate(
                $this->company
            );

        if (
            ! $eligibility[
                'eligible'
            ]
        ) {
            $this->addError(
                'exportResearch',
                $eligibility[
                    'message'
                ]
            );

            return;
        }

        $result =
            $queue->dispatch(
                $this->company
            );

        $this->reloadCompany();

        unset(
            $this->exportResearchEligibility
        );

        if (
            $result->research_status
            === 'queued'
        ) {
            session()->flash(
                'success',
                'Pesquisa de exportação '
                .'enviada para processamento.'
            );
        }
    }

    public function researchExportsForce(
        ExportResearchQueueService $queue
    ): void {
        Gate::authorize(
            'forceResearchExports',
            $this->company,
        );

        if (
            ! $this->exportResearchConfigured()
        ) {
            $this->addError(
                'exportResearch',
                'A pesquisa externa está '
                .'desativada ou sem provider '
                .'configurado.'
            );

            return;
        }

        /*
         * Pesquisa manual:
         *
         * ignora ICP, CRM, cooldown e demais
         * bloqueios da automação porque houve
         * uma decisão explícita do usuário.
         */
        $result =
            $queue->dispatch(
                company: $this->company,

                force: true,
            );

        $this->reloadCompany();

        unset(
            $this->exportResearchEligibility
        );

        if (
            $result->research_status
            === 'queued'
        ) {
            session()->flash(
                'success',
                'Pesquisa manual de exportação '
                .'enviada para processamento.'
            );
        }
    }

    public function refreshExportResearch(): void
    {
        Gate::authorize(
            'view',
            $this->company,
        );

        $this->reloadCompany();

        unset(
            $this->exportResearchEligibility
        );
    }

    public function exportStatusLabel(
        ?string $status
    ): string {
        return match ($status) {
            'yes' => 'Sim',

            'no' => 'Não',

            'uncertain' => 'Incerto',

            default => 'Não pesquisada',
        };
    }

    public function exportStatusClasses(
        ?string $status
    ): string {
        return match ($status) {
            'yes' => 'bg-emerald-500/15 '
                .'text-emerald-300',

            'no' => 'bg-rose-500/15 '
                .'text-rose-300',

            'uncertain' => 'bg-amber-500/15 '
                .'text-amber-300',

            default => 'bg-[var(--ec-surface-soft)] '
                .'text-[var(--ec-text-muted)]',
        };
    }

    public function exportDimensionLabel(
        string $dimension
    ): string {
        return match ($dimension) {
            'direct' => 'Exportação direta',

            'indirect' => 'Exportação indireta',

            'trading' => 'Trading',

            default => ucfirst($dimension),
        };
    }

    public function formatMoney(
        mixed $value
    ): string {
        if ($value === null || $value === '') {
            return '—';
        }

        return 'R$ '.number_format(
            (float) $value,
            2,
            ',',
            '.'
        );
    }
};
?>

<div
    class="ec-page-shell ec-dossier-page"
    x-data="{
        dossierTab:
            new URLSearchParams(
                window.location.search
            ).get('tab')
            || 'commercial',

        expandedTimeline: false,
        expandedEstablishments: false,
        expandedGroupCnaes: false,
        expandedMatrixCnaes: false,
        showUnitContacts: false,

        setDossierTab(tab) {
            this.dossierTab = tab;

            const url =
                new URL(
                    window.location.href
                );

            url.searchParams.set(
                'tab',
                tab
            );

            window.history.replaceState(
                {},
                '',
                url
            );
        }
    }"
    x-init="
        if (
            ! [
                'commercial',
                'company',
                'cnaes'
            ].includes(
                dossierTab
            )
        ) {
            dossierTab =
                'commercial';
        }
    "
>

    @php
        /*
         * Inteligência de exportação da empresa.
         *
         * Definida no início da view para ficar
         * disponível em todos os cards, painel
         * de pesquisa e bloco de evidências.
         */
        $export =
            $company->exportIntelligence;

        $crmReprospecting =
            $this->crmReprospecting;
    @endphp

    {{-- VOLTAR --}}
    <div>
        <a
            href="{{ route('companies.index') }}"
            wire:navigate
            class="ec-back-link"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                class="size-4"
            >
                <path d="m15 18-6-6 6-6" />
            </svg>

            Empresas
        </a>
    </div>


    {{-- CABEÇALHO DA EMPRESA --}}
    <section class="ec-dossier-hero">

        <div class="ec-dossier-company-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.7" stroke-linejoin="round" stroke-linecap="round">
                <path d="M4 21h16M7 21V8l5-4 5 4v13M4 21V11h3M17 11h3v10" />
                <path d="M10 10h1m2 0h1m-4 4h1m2 0h1m-3 7v-4h2v4" />
            </svg>
        </div>

        <div class="min-w-0">

            <div class="ec-page-kicker">
                Dossiê empresarial
            </div>

            <div class="ec-dossier-title-row">

                <h1 class="ec-dossier-title">
                    {{ $company->corporate_name }}
                </h1>

                @if ($this->matrix?->registration_status === 'ATIVA')

                    <span class="ec-status ec-status-active">
                        <span></span>
                        Ativa
                    </span>

                @elseif (
                    $this->matrix?->registration_status
                    === 'SUSPENSA'
                )

                    <span class="ec-status ec-status-warning">
                        <span></span>
                        Suspensa
                    </span>

                @elseif ($this->matrix?->registration_status)

                    <span class="ec-status ec-status-inactive">
                        <span></span>

                        {{
                            ucfirst(
                                mb_strtolower(
                                    $this->matrix
                                        ->registration_status
                                )
                            )
                        }}
                    </span>

                @endif

            </div>

            <div class="ec-dossier-meta">

                @if ($this->matrix)

                    <span>
                        {{ Cnpj::format($this->matrix->cnpj) }}
                    </span>

                @endif

                @if ($this->matrix?->municipality_name)

                    <span class="ec-meta-separator">
                        •
                    </span>

                    <span>
                        {{ $this->matrix->municipality_name }}

                        @if ($this->matrix->state)
                            / {{ $this->matrix->state }}
                        @endif
                    </span>

                @endif

                @if ($this->matrix?->fantasy_name)

                    <span class="ec-meta-separator">
                        •
                    </span>

                    <span>
                        {{ $this->matrix->fantasy_name }}
                    </span>

                @endif

            </div>

        </div>

        <a
            href="{{ route('companies.edit', $company) }}"
            wire:navigate
            class="ec-button-secondary"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                class="size-4"
            >
                <path
                    d="M12 20h9"
                />

                <path
                    d="M16.5 3.5a2.121 2.121 0 0 1 3 3L8 18l-4 1 1-4Z"
                />
            </svg>

            Editar empresa
        </a>

    </section>


    {{-- MENSAGENS --}}
    @if (session('success'))

        <div class="ec-alert-success">

            <span class="ec-alert-dot"></span>

            <span>
                {{ session('success') }}
            </span>

        </div>

    @endif


    {{-- NAVEGACAO DO DOSSIE --}}
    <nav
        id="dossier-tabs"
        class="ec-dossier-tabs"
        aria-label="Seções do dossiê"
    >

        <button
            type="button"
            class="ec-dossier-tab"
            :class="{
                'is-active':
                    dossierTab === 'commercial'
            }"
            @click="
                setDossierTab(
                    'commercial'
                )
            "
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
            >
                <path d="M3 3v18h18" />
                <path d="m7 15 4-4 3 3 5-6" />
            </svg>

            Comercial
        </button>


        <button
            type="button"
            class="ec-dossier-tab"
            :class="{
                'is-active':
                    dossierTab === 'company'
            }"
            @click="
                setDossierTab(
                    'company'
                )
            "
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
            >
                <path d="M3 21h18" />
                <path d="M6 21V7l6-4 6 4v14" />
                <path d="M9 10h1" />
                <path d="M14 10h1" />
                <path d="M9 14h1" />
                <path d="M14 14h1" />
            </svg>

            Empresa & grupo

            <span class="ec-dossier-tab-count">
                {{
                    $company
                        ->establishments
                        ->count()
                }}
            </span>
        </button>


        <button
            type="button"
            class="ec-dossier-tab"
            :class="{
                'is-active':
                    dossierTab === 'cnaes'
            }"
            @click="
                setDossierTab(
                    'cnaes'
                )
            "
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
            >
                <path d="M4 6h16" />
                <path d="M4 12h16" />
                <path d="M4 18h10" />
            </svg>

            CNAEs

            <span class="ec-dossier-tab-count">
                {{
                    $this
                        ->groupCnaes
                        ->count()
                }}
            </span>
        </button>

    </nav>


    {{--
    |--------------------------------------------------------------------------
    | Conteúdo do dossiê
    |--------------------------------------------------------------------------
    |
    | A lógica Livewire continua neste componente.
    |
    | A apresentação de cada aba foi movida para
    | partials menores para facilitar manutenção.
    |
    --}}

    @include(
        'partials.company-dossier.commercial'
    )

    @include(
        'partials.company-dossier.company'
    )

    @include(
        'partials.company-dossier.cnaes'
    )

</div>
