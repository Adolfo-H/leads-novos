<?php

use App\Models\Company;
use App\Services\EstablishmentService;
use App\Services\CrmReprospectingPolicyService;
use App\Services\ExportResearchEligibilityService;
use App\Services\ExportResearchQueueService;
use App\Services\SdrScoringService;
use App\Support\Cnpj;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Company $company;

    public string $newCnaeCode = '';

public string $newCnaeDescription = '';

public bool $newCnaePrimary = false;

public bool $showCnaeForm = false;

    public function mount(Company $company): void
    {
        $this->company = $company->load([
            'establishments.cnaes',
            'icpScore',
            'crmCheck',
            'exportIntelligence',
            'exportEvidence',
            'sdrScore',
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
                fn ($establishment): bool =>
                    trim(
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
                $value instanceof
                \DateTimeInterface
            ) {
                return $value->format(
                    'd/m/Y'
                );
            }

            return \Carbon\CarbonImmutable::parse(
                (string) $value
            )->format(
                'd/m/Y'
            );
        } catch (\Throwable) {
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
            fn ($value): bool =>
                $value !== ''
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

    public function hubSpotCompanyUrl(): ?string
    {
        $crm =
            $this->company
                ->crmCheck;

        if ($crm === null) {
            return null;
        }

        $portalId =
            trim(
                (string) config(
                    'services.hubspot.portal_id'
                )
            );

        $companyId =
            trim(
                (string) $crm
                    ->external_id
            );

        /*
         * Sempre reconstruímos o endereço
         * usando Portal ID + Company ID.
         *
         * Assim URLs antigas salvas no banco
         * não afetam o botão do dossiê.
         */
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
                (string) $crm
                    ->external_url
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
                ->establishments
            as $establishment
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
                        'value' =>
                            $email,

                        'establishments' =>
                            [],
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
                ]
                as $phone
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
                        'value' =>
                            $this
                                ->formatPhone(
                                    $digits
                                ),

                        'raw' =>
                            $digits,

                        'href' =>
                            'tel:+55'
                            .$digits,

                        'establishments' =>
                            [],
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
                'value' =>
                    $item['value'],

                'count' =>
                    count(
                        $locations
                    ),

                'locations' =>
                    $locations,
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
                'value' =>
                    $item['value'],

                'raw' =>
                    $item['raw'],

                'href' =>
                    $item['href'],

                'count' =>
                    count(
                        $locations
                    ),

                'locations' =>
                    $locations,
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
            'units_with_contact' =>
                $this
                    ->contactEstablishments
                    ->count(),

            'emails' =>
                $emails,

            'phones' =>
                $phones,
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
                ->establishments
            as $establishment
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
            $stateCounts
            as $state => $count
        ) {
            $states[] = [
                'state' =>
                    $state,

                'count' =>
                    $count,
            ];
        }

        $statuses = [];

        foreach (
            $statusCounts
            as $status => $count
        ) {
            $statuses[] = [
                'status' =>
                    $status,

                'count' =>
                    $count,
            ];
        }

        return [
            'total' =>
                $total,

            'active' =>
                $active,

            'inactive' =>
                $inactive,

            'unknown' =>
                $unknown,

            'active_states_count' =>
                count(
                    $stateCounts
                ),

            'active_municipalities_count' =>
                count(
                    $municipalities
                ),

            'states' =>
                $states,

            'statuses' =>
                $statuses,
        ];
    }

    #[Computed]
    public function groupCnaes(): Collection
    {
        $grouped = [];

        foreach (
            $this->company
                ->establishments
            as $establishment
        ) {
            foreach (
                $establishment->cnaes
                as $cnae
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
                        'code' =>
                            $code,

                        'description' =>
                            $cnae->description,

                        'units' =>
                            [],

                        'primary_units' =>
                            [],

                        'active_units' =>
                            [],
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
                        'code' =>
                            $item['code'],

                        'description' =>
                            $item[
                                'description'
                            ],

                        'units_count' =>
                            count(
                                $item[
                                    'units'
                                ]
                            ),

                        'active_units_count' =>
                            count(
                                $item[
                                    'active_units'
                                ]
                            ),

                        'primary_units_count' =>
                            count(
                                $item[
                                    'primary_units'
                                ]
                            ),

                        'locations' =>
                            $locations,
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
                fn ($cnae) =>
                    (bool) $cnae
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
                fn ($cnae) =>
                    ! (bool) $cnae
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
        } catch (\Throwable) {
            return '—';
        }
    }

public function toggleCnaeForm(): void
{
    $this->showCnaeForm =
        ! $this->showCnaeForm;

    if (! $this->showCnaeForm) {
        $this->resetCnaeForm();
    }
}

public function addCnae(
    EstablishmentService $service
): void {
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
                'code' =>
                    $validated[
                        'newCnaeCode'
                    ],

                'description' =>
                    $validated[
                        'newCnaeDescription'
                    ] ?: null,

                'is_primary' =>
                    $validated[
                        'newCnaePrimary'
                    ],
            ]
        );
    } catch (\InvalidArgumentException $exception) {
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
    } catch (\InvalidArgumentException) {
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
                \App\Contracts\ExportResearchProvider::class
            )->name();

        $apiKey =
            match ($provider) {
                'tavily' =>
                    config(
                        'services.tavily.api_key'
                    ),

                'openai-web-search' =>
                    config(
                        'services.openai.api_key'
                    ),

                default =>
                    null,
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
                    \App\Contracts\ExportResearchProvider::class
                )->name();
        }

        return match ($provider) {
            'tavily' =>
                'Tavily',

            'openai-web-search' =>
                'OpenAI Web',

            default =>
                ucfirst(
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
                company:
                    $this->company,

                force:
                    true,
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
        $this->reloadCompany();

        unset(
            $this->exportResearchEligibility
        );
    }

    public function exportStatusLabel(
        ?string $status
    ): string {
        return match ($status) {
            'yes' =>
                'Sim',

            'no' =>
                'Não',

            'uncertain' =>
                'Incerto',

            default =>
                'Não pesquisada',
        };
    }

    public function exportStatusClasses(
        ?string $status
    ): string {
        return match ($status) {
            'yes' =>
                'bg-emerald-500/15 '
                .'text-emerald-300',

            'no' =>
                'bg-rose-500/15 '
                .'text-rose-300',

            'uncertain' =>
                'bg-amber-500/15 '
                .'text-amber-300',

            default =>
                'bg-white/5 '
                .'text-[#7f87a7]',
        };
    }

    public function exportDimensionLabel(
        string $dimension
    ): string {
        return match ($dimension) {
            'direct' =>
                'Exportação direta',

            'indirect' =>
                'Exportação indireta',

            'trading' =>
                'Trading',

            default =>
                ucfirst($dimension),
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

<div class="ec-page-shell">

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


    {{-- INTELIGÊNCIA COMERCIAL --}}
    <section>

        <div class="ec-section-heading">

            <div>

                <h2 class="ec-section-title">
                    Inteligência comercial
                </h2>

                <p class="ec-section-description">
                    Situação atual da empresa dentro do processo de prospecção.
                </p>

            </div>

            <span class="ec-section-hint">
                Enriquecimento automático nas próximas etapas
            </span>

        </div>


        @php
            $researchStatus =
                $export?->research_status
                ?? 'idle';

            $researchRunning =
                in_array(
                    $researchStatus,
                    [
                        'queued',
                        'processing',
                    ],
                    true
                );

            $researchConfigured =
                $this
                    ->exportResearchConfigured();

            $researchEligibility =
                $this
                    ->exportResearchEligibility;

            $evidenceCount =
                $company
                    ->exportEvidence
                    ->count();
        @endphp


        {{-- PESQUISA DE EXPORTAÇÃO --}}
        <div
            @if ($researchRunning)
                wire:poll.2s="refreshExportResearch"
            @endif
            class="
                mb-5 overflow-hidden
                rounded-2xl
                border border-white/[0.07]
                bg-white/[0.025]
            "
        >

            <div
                class="
                    flex flex-col gap-4
                    px-5 py-4
                    lg:flex-row
                    lg:items-center
                    lg:justify-between
                "
            >

                <div
                    class="
                        flex min-w-0
                        items-center gap-4
                    "
                >

                    <div
                        class="
                            flex size-11
                            shrink-0
                            items-center
                            justify-center
                            rounded-xl
                            border
                            border-cyan-300/10
                            bg-cyan-300/[0.06]
                            text-cyan-300
                        "
                    >

                        @if ($researchRunning)

                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                class="
                                    size-5
                                    animate-spin
                                "
                            >
                                <circle
                                    cx="12"
                                    cy="12"
                                    r="9"
                                    stroke="currentColor"
                                    stroke-opacity=".20"
                                    stroke-width="3"
                                />

                                <path
                                    d="
                                        M21 12
                                        a9 9 0 0 0-9-9
                                    "
                                    stroke="currentColor"
                                    stroke-width="3"
                                    stroke-linecap="round"
                                />
                            </svg>

                        @elseif (
                            $researchStatus
                            === 'completed'
                        )

                            <svg
                                xmlns="
                                    http://www.w3.org/2000/svg
                                "
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                class="
                                    size-5
                                    text-emerald-300
                                "
                            >
                                <path
                                    d="
                                        m5 12
                                        4 4
                                        L19 6
                                    "
                                />
                            </svg>

                        @else

                            <svg
                                xmlns="
                                    http://www.w3.org/2000/svg
                                "
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                class="size-5"
                            >
                                <circle
                                    cx="11"
                                    cy="11"
                                    r="7"
                                />

                                <path
                                    d="m20 20-4-4"
                                />
                            </svg>

                        @endif

                    </div>


                    <div class="min-w-0">

                        <div
                            class="
                                text-[11px]
                                font-semibold
                                uppercase
                                tracking-[0.16em]
                                text-[#737e9f]
                            "
                        >
                            Pesquisa de exportação
                        </div>


                        @if ($researchRunning)

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                Pesquisando fontes públicas...
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#8993b3]
                                "
                            >
                                O processamento está sendo
                                executado em segundo plano.
                            </div>

                        @elseif (
                            $researchStatus
                            === 'completed'
                        )

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-emerald-300
                                "
                            >
                                Pesquisa concluída
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#8993b3]
                                "
                            >
                                {{ $evidenceCount }}
                                evidência(s) pública(s)
                                registrada(s).
                            </div>

                        @elseif (
                            $researchStatus
                            === 'failed'
                        )

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-rose-300
                                "
                            >
                                Falha na pesquisa
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#8993b3]
                                "
                            >
                                {{
                                    $export
                                        ?->research_error
                                    ?: 'Não foi possível concluir.'
                                }}
                            </div>

                        @elseif (! $researchConfigured)

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                Pesquisa pública desativada
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#8993b3]
                                "
                            >
                                Nenhuma API externa será
                                utilizada até você ativar
                                um provider.
                            </div>

                        @elseif (
                            ! $researchEligibility[
                                'eligible'
                            ]
                        )

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                Pesquisa automática bloqueada
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#8993b3]
                                "
                            >
                                {{
                                    $researchEligibility[
                                        'message'
                                    ]
                                }}
                            </div>

                        @else

                            <div
                                class="
                                    mt-1 font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                Empresa elegível para pesquisa
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#8993b3]
                                "
                            >
                                O Prospector pesquisará
                                exportação direta, indireta
                                e relação com tradings.
                            </div>

                        @endif

                    </div>

                </div>


                <div
                    class="
                        flex shrink-0
                        items-center gap-3
                    "
                >

                    @if ($researchConfigured)

                        <span
                            class="
                                rounded-full
                                border border-emerald-400/15
                                bg-emerald-400/[0.06]
                                px-3 py-1.5
                                text-xs
                                font-medium
                                text-emerald-300
                            "
                        >
                            {{
                                $this
                                    ->exportResearchProviderLabel()
                            }}
                            ativo
                        </span>

                    @else

                        <span
                            class="
                                rounded-full
                                border border-white/[0.06]
                                bg-white/[0.03]
                                px-3 py-1.5
                                text-xs
                                font-medium
                                text-[#7f87a7]
                            "
                        >
                            Provider desativado
                        </span>

                    @endif


                    @if (
                        $researchConfigured
                        && ! $researchRunning
                    )

                        @if (
                            $researchStatus
                            === 'completed'
                        )

                            <button
                                type="button"
                                wire:click="
                                    researchExportsForce
                                "
                                wire:loading.attr="
                                    disabled
                                "
                                wire:target="
                                    researchExportsForce
                                "
                                class="
                                    ec-button-secondary
                                "
                                title="
                                    Executa novamente
                                    3 buscas no Tavily
                                "
                            >
                                <span
                                    wire:loading.remove
                                    wire:target="
                                        researchExportsForce
                                    "
                                >
                                    Pesquisar novamente
                                </span>

                                <span
                                    wire:loading
                                    wire:target="
                                        researchExportsForce
                                    "
                                >
                                    Enviando...
                                </span>
                            </button>

                        @elseif (
                            ! $researchEligibility[
                                'eligible'
                            ]
                        )

                            <button
                                type="button"
                                wire:click="
                                    researchExportsForce
                                "
                                wire:loading.attr="
                                    disabled
                                "
                                wire:target="
                                    researchExportsForce
                                "
                                class="
                                    ec-button-secondary
                                "
                                title="
                                    Ignora a peneira automática
                                    e executa 3 buscas no Tavily
                                "
                            >
                                <span
                                    wire:loading.remove
                                    wire:target="
                                        researchExportsForce
                                    "
                                >
                                    Pesquisar mesmo assim
                                </span>

                                <span
                                    wire:loading
                                    wire:target="
                                        researchExportsForce
                                    "
                                >
                                    Enviando...
                                </span>
                            </button>

                        @else

                            <button
                                type="button"
                                wire:click="
                                    researchExports
                                "
                                wire:loading.attr="
                                    disabled
                                "
                                wire:target="
                                    researchExports
                                "
                                class="
                                    ec-button-primary
                                "
                            >
                                <span
                                    wire:loading.remove
                                    wire:target="
                                        researchExports
                                    "
                                >
                                    Pesquisar exportações
                                </span>

                                <span
                                    wire:loading
                                    wire:target="
                                        researchExports
                                    "
                                >
                                    Enviando...
                                </span>
                            </button>

                        @endif

                    @endif

                </div>

            </div>

        </div>


        @error('exportResearch')

            <div
                class="
                    mb-5 rounded-xl
                    border border-amber-400/15
                    bg-amber-400/[0.06]
                    px-4 py-3
                    text-sm
                    text-amber-200
                "
            >
                {{ $message }}
            </div>

        @enderror


        <div class="ec-intelligence-grid">

            {{-- CRM --}}

            @php
                $crm = $company->crmCheck;

                $crmStatusLabel = match ($crm?->status) {
                    'client' => 'Cliente',
                    'opportunity' => 'Oportunidade',
                    'prospected' => 'Prospectado',
                    'known' => 'Conhecido',
                    'not_found' => 'Não encontrado',
                    default => 'Não verificado',
                };

                $crmStatusClasses = match ($crm?->status) {
                    'client' =>
                        'bg-emerald-500/15 text-emerald-300',

                    'opportunity' =>
                        'bg-amber-500/15 text-amber-300',

                    'prospected' =>
                        'bg-sky-500/15 text-sky-300',

                    'known' =>
                        'bg-violet-500/15 text-violet-300',

                    'not_found' =>
                        'bg-white/5 text-[#9ba3c2]',

                    default =>
                        'bg-white/5 text-[#7f87a7]',
                };

                $crmCaption = match ($crm?->status) {
                    'client' =>
                        'Já é cliente no CRM',

                    'opportunity' =>
                        'Já possui oportunidade comercial',

                    'prospected' =>
                        $crmReprospecting
                            ? (
                                $crmReprospecting[
                                    'eligible'
                                ]
                                    ? 'Reprospecção liberada'
                                    : 'Aguardando reprospecção'
                            )
                            : 'Já houve contato comercial',

                    'known' =>
                        'Registro localizado no CRM',

                    'not_found' =>
                        'Não localizado no HubSpot',

                    default =>
                        'Base comercial',
                };
            @endphp

            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        CRM
                    </span>

                    @if ($crm)

                        <span
                            class="
                                rounded-full px-2 py-1
                                text-[10px] font-bold
                                uppercase tracking-wide
                                {{ $crmStatusClasses }}
                            "
                        >
                            {{ $crmStatusLabel }}
                        </span>

                    @else

                        <span class="ec-intelligence-dot"></span>

                    @endif

                </div>

                <div class="ec-intelligence-value">
                    {{ $crmStatusLabel }}
                </div>

                <div class="ec-intelligence-caption">
                    {{ $crmCaption }}
                </div>

                @if (
                    $crm?->status
                        === 'prospected'
                    && $crmReprospecting
                )

                    <div
                        class="
                            mt-2 text-[11px]
                            font-medium
                            {{
                                $crmReprospecting[
                                    'eligible'
                                ]
                                    ? 'text-emerald-300'
                                    : 'text-amber-300'
                            }}
                        "
                    >

                        @if (
                            $crmReprospecting[
                                'eligible'
                            ]
                        )

                            Reprospecção liberada

                        @elseif (
                            $crmReprospecting[
                                'next_allowed_at'
                            ]
                        )

                            Reprospecção a partir de

                            {{
                                $this
                                    ->formatReprospectingDate(
                                        $crmReprospecting[
                                            'next_allowed_at'
                                        ]
                                    )
                            }}

                        @else

                            Reprospecção depende
                            de revisão manual

                        @endif

                    </div>

                @endif

                @if (
                    $crm?->matched_value
                    || $crm?->external_domain
                )

                    <div
                        class="
                            mt-2 truncate text-[11px]
                            text-[#7f87a7]
                        "
                        title="{{
                            $crm->matched_value
                            ?? $crm->external_domain
                        }}"
                    >
                        {{
                            $crm->matched_value
                            ?? $crm->external_domain
                        }}
                    </div>

                @endif

            </div>


            {{-- ICP --}}
            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        ICP
                    </span>

                    @if ($company->icpScore)

                        <span
                            class="
                                inline-flex size-7 items-center
                                justify-center rounded-full
                                text-xs font-bold
                                {{ match ($company->icpScore->grade) {
                                    'A' => 'bg-emerald-500/15 text-emerald-300',
                                    'B' => 'bg-sky-500/15 text-sky-300',
                                    'C' => 'bg-amber-500/15 text-amber-300',
                                    default => 'bg-rose-500/15 text-rose-300',
                                } }}
                            "
                        >
                            {{ $company->icpScore->grade }}
                        </span>

                    @else

                        <span class="ec-intelligence-dot"></span>

                    @endif

                </div>

                @if ($company->icpScore)

                    <div class="ec-intelligence-value">
                        {{ $company->icpScore->score }}/100
                    </div>

                    <div class="ec-intelligence-caption">
                        {{ $company->icpScore->label }}
                    </div>

                @else

                    <div class="ec-intelligence-value">
                        Não calculado
                    </div>

                    <div class="ec-intelligence-caption">
                        Perfil ideal
                    </div>

                @endif

            </div>


            {{-- EXPORTAÇÃO --}}
            @php
                $exportResearchSummary =
                    $this->exportResearchSummary;

                $exportAssessment =
                    is_array(
                        $exportResearchSummary
                    )
                        ? data_get(
                            $exportResearchSummary,
                            'assessment'
                        )
                        : null;

                $exportAssessmentStatus =
                    is_array(
                        $exportAssessment
                    )
                        ? (
                            $exportAssessment[
                                'status'
                            ]
                            ?? null
                        )
                        : null;

                $exportCardLabel =
                    is_array(
                        $exportAssessment
                    )
                        ? (
                            $exportAssessment[
                                'label'
                            ]
                            ?? 'Pesquisa concluída'
                        )
                        : match (
                            $export
                                ?->research_status
                        ) {
                            'completed' =>
                                'Pesquisa concluída',

                            'queued' =>
                                'Pesquisa na fila',

                            'processing' =>
                                'Pesquisa em andamento',

                            'failed' =>
                                'Pesquisa com erro',

                            default =>
                                'Não pesquisada',
                        };

                $exportCardClasses =
                    match (
                        $exportAssessmentStatus
                    ) {
                        'identified' =>
                            'bg-emerald-500/15 text-emerald-300',

                        'indications' =>
                            'bg-cyan-500/15 text-cyan-300',

                        'inconclusive' =>
                            'bg-amber-500/15 text-amber-300',

                        'not_supported' =>
                            'bg-rose-500/15 text-rose-300',

                        default =>
                            'bg-white/5 text-[#9ba3c2]',
                    };

                $exportModalities =
                    is_array(
                        $exportAssessment
                    )
                    && is_array(
                        $exportAssessment[
                            'modalities'
                        ]
                        ?? null
                    )
                        ? $exportAssessment[
                            'modalities'
                        ]
                        : [];

                $exportEvidenceCount =
                    $company
                        ->exportEvidence
                        ->count();

                $exportCardCaption =
                    $exportModalities !== []
                        ? implode(
                            ' · ',
                            $exportModalities
                        )
                        : (
                            $exportEvidenceCount > 0
                                ? $exportEvidenceCount
                                    .' fonte(s) analisada(s)'
                                : 'Pesquisa pública de exportação'
                        );
            @endphp

            <div class="ec-intelligence-card">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        Exportação
                    </span>

                    <span
                        class="
                            rounded-full
                            px-2 py-1
                            text-[10px]
                            font-bold uppercase
                            {{ $exportCardClasses }}
                        "
                    >
                        {{
                            $export
                                ?->research_status
                                === 'completed'
                                    ? 'Analisada'
                                    : (
                                        $export
                                            ?->research_status
                                            === 'processing'
                                            ? 'Analisando'
                                            : (
                                                $export
                                                    ?->research_status
                                                    === 'queued'
                                                    ? 'Na fila'
                                                    : 'Pendente'
                                            )
                                    )
                        }}
                    </span>

                </div>

                <div class="ec-intelligence-value">
                    {{ $exportCardLabel }}
                </div>

                <div class="ec-intelligence-caption">
                    {{ $exportCardCaption }}
                </div>

            </div>


            {{-- SCORE --}}
            @php
                $sdr =
                    $company->sdrScore;
            @endphp

            <div class="ec-intelligence-card ec-intelligence-score">

                <div class="ec-intelligence-top">

                    <span class="ec-intelligence-label">
                        Score
                    </span>

                    @if ($sdr)

                        <span
                            class="
                                rounded-full
                                px-2 py-1
                                text-[10px]
                                font-bold
                                uppercase
                                {{
                                    match ($sdr->priority) {
                                        'very_high' =>
                                            'bg-emerald-500/15 text-emerald-300',

                                        'high' =>
                                            'bg-cyan-500/15 text-cyan-300',

                                        'medium' =>
                                            'bg-amber-500/15 text-amber-300',

                                        'blocked' =>
                                            'bg-rose-500/15 text-rose-300',

                                        default =>
                                            'bg-white/5 text-[#8e97b8]',
                                    }
                                }}
                            "
                        >
                            @if (! $sdr->is_eligible)
                                Bloqueado
                            @elseif ($sdr->is_provisional)
                                Provisório
                            @else
                                SDR
                            @endif
                        </span>

                    @else

                        <span class="ec-intelligence-dot"></span>

                    @endif

                </div>

                @if (
                    $sdr
                    && ! $sdr->is_eligible
                )

                    <div
                        class="
                            mt-3
                            text-base
                            font-bold
                            text-rose-300
                        "
                    >
                        Não priorizar
                    </div>

                    <div class="ec-intelligence-caption">
                        {{
                            $sdr->blocked_reason
                            ?: 'Bloqueio comercial'
                        }}
                    </div>

                @elseif ($sdr)

                    <div class="ec-score-value">
                        {{ $sdr->score }}/100
                    </div>

                    <div class="ec-intelligence-caption">
                        {{ $sdr->label }}

                        @if ($sdr->is_provisional)
                            · Provisório
                        @endif
                    </div>

                @else

                    <div class="ec-score-value">
                        —
                    </div>

                    <div class="ec-intelligence-caption">
                        Prioridade SDR
                    </div>

                @endif

            </div>

        </div>

        {{-- RESUMO DA PESQUISA DE EXPORTAÇÃO --}}
        @php
            $researchSummary =
                $this->exportResearchSummary;
        @endphp

        @if ($researchSummary)

            <div
                class="
                    mb-5 overflow-hidden
                    rounded-2xl
                    border border-cyan-300/10
                    bg-cyan-300/[0.025]
                "
            >

                <div
                    class="
                        border-b border-white/[0.06]
                        px-5 py-4
                    "
                >
                    <div
                        class="
                            text-[11px]
                            font-semibold uppercase
                            tracking-[0.16em]
                            text-cyan-300
                        "
                    >
                        Leitura comercial
                    </div>

                    <div
                        class="
                            mt-1 text-base
                            font-semibold
                            text-[#eef1ff]
                        "
                    >
                        Resumo da pesquisa de exportação
                    </div>

                    <p
                        class="
                            mt-2 max-w-4xl
                            text-sm leading-6
                            text-[#a9b1cc]
                        "
                    >
                        {{
                            $researchSummary[
                                'headline'
                            ]
                        }}
                    </p>
                </div>


                @php
                    $assessment =
                        data_get(
                            $researchSummary,
                            'assessment',
                            []
                        );

                    $assessmentLabel =
                        is_array(
                            $assessment
                        )
                            ? (
                                $assessment[
                                    'label'
                                ]
                                ?? 'Pesquisa concluída'
                            )
                            : 'Pesquisa concluída';

                    $modalities =
                        is_array(
                            $assessment
                        )
                        && is_array(
                            $assessment[
                                'modalities'
                            ]
                            ?? null
                        )
                            ? $assessment[
                                'modalities'
                            ]
                            : [];
                @endphp

                <div
                    class="
                        grid gap-3
                        px-5 py-4
                        md:grid-cols-3
                    "
                >

                    <div
                        class="
                            rounded-xl
                            border border-white/[0.06]
                            bg-white/[0.025]
                            p-4
                        "
                    >
                        <div
                            class="
                                text-[10px]
                                font-semibold uppercase
                                tracking-[0.12em]
                                text-[#737e9f]
                            "
                        >
                            Resultado
                        </div>

                        <div
                            class="
                                mt-2 text-sm
                                font-semibold
                                text-[#eef1ff]
                            "
                        >
                            {{ $assessmentLabel }}
                        </div>
                    </div>


                    <div
                        class="
                            rounded-xl
                            border border-white/[0.06]
                            bg-white/[0.025]
                            p-4
                        "
                    >
                        <div
                            class="
                                text-[10px]
                                font-semibold uppercase
                                tracking-[0.12em]
                                text-[#737e9f]
                            "
                        >
                            Modalidade identificada
                        </div>

                        <div
                            class="
                                mt-2 text-sm
                                font-semibold
                                text-[#eef1ff]
                            "
                        >
                            {{
                                $modalities !== []
                                    ? implode(
                                        ' · ',
                                        $modalities
                                    )
                                    : 'Não identificada'
                            }}
                        </div>
                    </div>


                    <div
                        class="
                            rounded-xl
                            border border-white/[0.06]
                            bg-white/[0.025]
                            p-4
                        "
                    >
                        <div
                            class="
                                text-[10px]
                                font-semibold uppercase
                                tracking-[0.12em]
                                text-[#737e9f]
                            "
                        >
                            Fontes analisadas
                        </div>

                        <div
                            class="
                                mt-2 text-sm
                                font-semibold
                                text-[#eef1ff]
                            "
                        >
                            {{
                                $researchSummary[
                                    'evidence_count'
                                ]
                            }}
                        </div>
                    </div>

                </div>


                @if (
                    $researchSummary[
                        'products'
                    ] !== []
                    || $researchSummary[
                        'markets'
                    ] !== []
                )

                    <div
                        class="
                            grid gap-4
                            border-t border-white/[0.06]
                            px-5 py-4
                            md:grid-cols-2
                        "
                    >

                        @if (
                            $researchSummary[
                                'products'
                            ] !== []
                        )

                            <div>
                                <div
                                    class="
                                        text-[10px]
                                        font-semibold uppercase
                                        tracking-[0.12em]
                                        text-[#737e9f]
                                    "
                                >
                                    Produtos citados
                                </div>

                                <div
                                    class="
                                        mt-2 flex flex-wrap
                                        gap-2
                                    "
                                >
                                    @foreach (
                                        $researchSummary[
                                            'products'
                                        ] as $product
                                    )
                                        <span
                                            class="
                                                rounded-full
                                                border border-white/[0.07]
                                                bg-white/[0.035]
                                                px-2.5 py-1
                                                text-xs
                                                text-[#c3c9df]
                                            "
                                        >
                                            {{ $product }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>

                        @endif


                        @if (
                            $researchSummary[
                                'markets'
                            ] !== []
                        )

                            <div>
                                <div
                                    class="
                                        text-[10px]
                                        font-semibold uppercase
                                        tracking-[0.12em]
                                        text-[#737e9f]
                                    "
                                >
                                    Mercados citados
                                </div>

                                <div
                                    class="
                                        mt-2 flex flex-wrap
                                        gap-2
                                    "
                                >
                                    @foreach (
                                        $researchSummary[
                                            'markets'
                                        ] as $market
                                    )
                                        <span
                                            class="
                                                rounded-full
                                                border border-white/[0.07]
                                                bg-white/[0.035]
                                                px-2.5 py-1
                                                text-xs
                                                text-[#c3c9df]
                                            "
                                        >
                                            {{ $market }}
                                        </span>
                                    @endforeach
                                </div>
                            </div>

                        @endif

                    </div>

                @endif


                <div
                    class="
                        flex flex-wrap gap-x-5 gap-y-2
                        border-t border-white/[0.06]
                        px-5 py-3
                        text-[11px]
                        text-[#737e9f]
                    "
                >
                    <span>
                        {{
                            $researchSummary[
                                'evidence_count'
                            ]
                        }} evidência(s)
                    </span>

                    <span>
                        {{
                            $researchSummary[
                                'positive_count'
                            ]
                        }} sinal(is) de exportação
                    </span>

                    <span>
                        {{
                            $researchSummary[
                                'neutral_count'
                            ]
                        }} fonte(s) contextual(is)
                    </span>

                    <span>
                        {{
                            $researchSummary[
                                'negative_count'
                            ]
                        }} sinal(is) contrário(s)
                    </span>
                </div>

            </div>

        @endif


        {{-- EVIDÊNCIAS DE EXPORTAÇÃO --}}
        @if (
            $company
                ->exportEvidence
                ->isNotEmpty()
        )

            <details
                class="
                    mt-4 overflow-hidden
                    rounded-xl
                    border border-white/5
                    bg-white/[0.025]
                "
            >

                <summary
                    class="
                        flex cursor-pointer
                        list-none
                        items-center
                        justify-between
                        px-5 py-4
                        text-sm
                        font-semibold
                        text-[#d9ddef]
                        transition
                        hover:bg-white/[0.025]
                    "
                >

                    <span>
                        Evidências de exportação
                    </span>

                    <span
                        class="
                            text-xs
                            font-medium
                            text-[#7f87a7]
                        "
                    >
                        {{
                            $company
                                ->exportEvidence
                                ->count()
                        }}
                        fonte(s)
                    </span>

                </summary>


                <div
                    class="
                        border-t border-white/5
                        p-5
                    "
                >

                    <div class="space-y-3">

                        @foreach (
                            $company
                                ->exportEvidence
                                ->sortByDesc(
                                    'created_at'
                                )
                            as $evidence
                        )

                            <div
                                class="
                                    rounded-xl
                                    border
                                    border-white/[0.06]
                                    bg-[#171d3c]/45
                                    p-4
                                "
                            >

                                <div
                                    class="
                                        flex flex-col
                                        gap-3
                                        sm:flex-row
                                        sm:items-start
                                        sm:justify-between
                                    "
                                >

                                    <div class="min-w-0">

                                        <div
                                            class="
                                                flex flex-wrap
                                                items-center
                                                gap-2
                                            "
                                        >

                                            <span
                                                class="
                                                    rounded-full
                                                    bg-cyan-300/10
                                                    px-2.5 py-1
                                                    text-[10px]
                                                    font-bold
                                                    uppercase
                                                    text-cyan-300
                                                "
                                            >
                                                {{
                                                    $this
                                                        ->exportDimensionLabel(
                                                            $evidence
                                                                ->dimension
                                                        )
                                                }}
                                            </span>

                                            <span
                                                class="
                                                    text-xs
                                                    font-semibold
                                                    text-[#aeb6d1]
                                                "
                                            >
                                                {{
                                                    match (
                                                        $evidence
                                                            ->signal
                                                    ) {
                                                        'positive' =>
                                                            'Sinal relevante',

                                                        'negative' =>
                                                            'Sinal contrário',

                                                        default =>
                                                            'Fonte contextual',
                                                    }
                                                }}
                                            </span>

                                        </div>


                                        <div
                                            class="
                                                mt-2
                                                text-sm
                                                font-semibold
                                                text-[#eef1ff]
                                            "
                                        >
                                            {{
                                                $evidence
                                                    ->title
                                                ?: (
                                                    $evidence
                                                        ->source_name
                                                    ?: 'Evidência pública'
                                                )
                                            }}
                                        </div>


                                        <div
                                            class="
                                                mt-1
                                                text-xs
                                                leading-5
                                                text-[#929bbb]
                                            "
                                        >
                                            {{
                                                $evidence
                                                    ->evidence_text
                                            }}
                                        </div>


                                        <div
                                            class="
                                                mt-2
                                                text-[11px]
                                                text-[#697598]
                                            "
                                        >
                                            Fonte:

                                            {{
                                                $evidence
                                                    ->source_name
                                                ?: $evidence
                                                    ->source_type
                                            }}
                                        </div>

                                    </div>


                                    @if (
                                        $evidence
                                            ->source_url
                                    )

                                        <a
                                            href="{{
                                                $evidence
                                                    ->source_url
                                            }}"
                                            target="_blank"
                                            rel="
                                                noopener
                                                noreferrer
                                            "
                                            class="
                                                inline-flex
                                                shrink-0
                                                items-center
                                                gap-1
                                                text-xs
                                                font-semibold
                                                text-cyan-300
                                                hover:text-cyan-200
                                            "
                                        >
                                            Abrir fonte ↗
                                        </a>

                                    @endif

                                </div>

                            </div>

                        @endforeach

                    </div>

                </div>

            </details>

        @endif


        @if ($company->crmCheck)

            @php
                $crm =
                    $company->crmCheck;

                $crmReprospecting =
                    $this->crmReprospecting;
            @endphp

            <details
                class="
                    mt-4 overflow-hidden rounded-xl
                    border border-white/5
                    bg-white/[0.025]
                "
            >

                <summary
                    class="
                        flex cursor-pointer list-none
                        items-center justify-between
                        px-5 py-4
                        text-sm font-semibold
                        text-[#d9ddef]
                        transition
                        hover:bg-white/[0.025]
                    "
                >
                    <span>
                        Detalhamento do CRM
                    </span>

                    <span
                        class="
                            text-xs font-medium
                            text-[#7f87a7]
                        "
                    >
                        {{ $crmStatusLabel }}
                        •
                        {{ strtoupper($crm->provider) }}
                    </span>
                </summary>

                <div
                    class="
                        border-t border-white/5
                        px-5 py-5
                    "
                >

                    <div
                        class="
                            grid gap-4
                            sm:grid-cols-2
                            xl:grid-cols-4
                        "
                    >

                        <div>
                            <div class="ec-field-label">
                                Status comercial
                            </div>

                            <div
                                class="
                                    mt-1 text-sm font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                {{ $crmStatusLabel }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Empresa no CRM
                            </div>

                            <div
                                class="
                                    mt-1 text-sm font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                {{
                                    $crm->external_name
                                    ?: '—'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Encontrado por
                            </div>

                            <div
                                class="
                                    mt-1 text-sm
                                    text-[#d9ddef]
                                "
                            >
                                @if ($crm->matched_by)

                                    {{
                                        match (
                                            $crm->matched_by
                                        ) {
                                            'domain' =>
                                                'Domínio',

                                            'name' =>
                                                'Nome',

                                            default =>
                                                ucfirst(
                                                    $crm->matched_by
                                                ),
                                        }
                                    }}

                                    @if ($crm->matched_value)
                                        ·
                                        {{ $crm->matched_value }}
                                    @endif

                                @else
                                    —
                                @endif
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Lifecycle HubSpot (informativo)
                            </div>

                            <div
                                class="
                                    mt-1 text-sm
                                    text-[#d9ddef]
                                "
                            >
                                {{
                                    $crm->lifecycle_stage
                                    ?: '—'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Interações registradas
                            </div>

                            <div
                                class="
                                    mt-1 text-sm font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                {{ $crm->contacted_count }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Negócios associados
                            </div>

                            <div
                                class="
                                    mt-1 text-sm font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                {{
                                    $crm
                                        ->associated_deals_count
                                }}
                            </div>
                        </div>

                        @if (
                            $crm->status
                                === 'prospected'
                            && $crmReprospecting
                        )

                            <div>

                                <div class="ec-field-label">
                                    Última atividade considerada
                                </div>

                                <div
                                    class="
                                        mt-1 text-sm
                                        text-[#d9ddef]
                                    "
                                >
                                    {{
                                        $this
                                            ->formatReprospectingDate(
                                                $crmReprospecting[
                                                    'last_activity_at'
                                                ]
                                            )
                                    }}
                                </div>

                            </div>


                            <div>

                                <div class="ec-field-label">
                                    Reprospecção
                                </div>

                                <div
                                    class="
                                        mt-1 text-sm
                                        font-semibold
                                        {{
                                            $crmReprospecting[
                                                'eligible'
                                            ]
                                                ? 'text-emerald-300'
                                                : 'text-amber-300'
                                        }}
                                    "
                                >

                                    @if (
                                        $crmReprospecting[
                                            'eligible'
                                        ]
                                    )

                                        Liberada agora

                                    @elseif (
                                        $crmReprospecting[
                                            'next_allowed_at'
                                        ]
                                    )

                                        A partir de

                                        {{
                                            $this
                                                ->formatReprospectingDate(
                                                    $crmReprospecting[
                                                        'next_allowed_at'
                                                    ]
                                                )
                                        }}

                                    @else

                                        Revisão manual necessária

                                    @endif

                                </div>

                            </div>

                        @endif


                        <div>
                            <div class="ec-field-label">
                                Último contato
                            </div>

                            <div
                                class="
                                    mt-1 text-sm
                                    text-[#d9ddef]
                                "
                            >
                                {{
                                    $crm->last_contacted_at
                                        ?->format(
                                            'd/m/Y H:i'
                                        )
                                    ?? '—'
                                }}
                            </div>
                        </div>

                        <div>
                            <div class="ec-field-label">
                                Verificado em
                            </div>

                            <div
                                class="
                                    mt-1 text-sm
                                    text-[#d9ddef]
                                "
                            >
                                {{
                                    $crm->checked_at
                                        ?->format(
                                            'd/m/Y H:i'
                                        )
                                    ?? '—'
                                }}
                            </div>
                        </div>

                    </div>

                    {{-- NEGÓCIOS HUBSPOT --}}
                    @php
                        $crmDeals =
                            data_get(
                                $crm->metadata,
                                'deals',
                                []
                            );

                        $dealSummary =
                            data_get(
                                $crm->metadata,
                                'deal_summary',
                                []
                            );

                        $crmDeals =
                            is_array($crmDeals)
                                ? $crmDeals
                                : [];
                    @endphp

                    @if ($crmDeals !== [])

                        <div
                            class="
                                mt-5 border-t
                                border-white/5
                                pt-5
                            "
                        >

                            <div
                                class="
                                    flex flex-col gap-2
                                    sm:flex-row
                                    sm:items-center
                                    sm:justify-between
                                "
                            >

                                <div>

                                    <div
                                        class="
                                            text-sm
                                            font-semibold
                                            text-[#eef1ff]
                                        "
                                    >
                                        Negócios HubSpot
                                    </div>

                                    <div
                                        class="
                                            mt-0.5 text-xs
                                            text-[#7f87a7]
                                        "
                                    >
                                        Negócios associados
                                        usados para classificar
                                        o status comercial.
                                    </div>

                                </div>

                                <div
                                    class="
                                        text-xs
                                        font-medium
                                        text-[#8f99bb]
                                    "
                                >
                                    {{
                                        data_get(
                                            $dealSummary,
                                            'active',
                                            0
                                        )
                                    }}
                                    ativo(s)

                                    ·

                                    {{
                                        data_get(
                                            $dealSummary,
                                            'won',
                                            0
                                        )
                                    }}
                                    ganho(s)

                                    ·

                                    {{
                                        data_get(
                                            $dealSummary,
                                            'closed_lost',
                                            0
                                        )
                                    }}
                                    encerrado(s)
                                </div>

                            </div>


                            <div
                                class="
                                    mt-4 grid gap-3
                                    lg:grid-cols-2
                                "
                            >

                                @foreach (
                                    $crmDeals
                                    as $deal
                                )

                                    @php
                                        $dealWon =
                                            (bool) (
                                                $deal[
                                                    'is_closed_won'
                                                ]
                                                ?? false
                                            );

                                        $dealClosed =
                                            (bool) (
                                                $deal[
                                                    'is_closed'
                                                ]
                                                ?? false
                                            );

                                        $dealState =
                                            $dealWon
                                                ? 'Ganho'
                                                : (
                                                    $dealClosed
                                                        ? 'Encerrado'
                                                        : 'Ativo'
                                                );

                                        $dealStateClasses =
                                            $dealWon
                                                ? 'bg-emerald-500/15 text-emerald-300'
                                                : (
                                                    $dealClosed
                                                        ? 'bg-rose-500/15 text-rose-300'
                                                        : 'bg-amber-500/15 text-amber-300'
                                                );
                                    @endphp

                                    <div
                                        class="
                                            rounded-xl
                                            border
                                            border-white/[0.06]
                                            bg-white/[0.025]
                                            p-4
                                        "
                                    >

                                        <div
                                            class="
                                                flex items-start
                                                justify-between
                                                gap-3
                                            "
                                        >

                                            <div
                                                class="
                                                    min-w-0
                                                "
                                            >

                                                <div
                                                    class="
                                                        truncate
                                                        text-sm
                                                        font-semibold
                                                        text-[#eef1ff]
                                                    "
                                                    title="{{
                                                        $deal[
                                                            'name'
                                                        ]
                                                        ?? 'Negócio sem nome'
                                                    }}"
                                                >
                                                    {{
                                                        $deal[
                                                            'name'
                                                        ]
                                                        ?? 'Negócio sem nome'
                                                    }}
                                                </div>

                                                <div
                                                    class="
                                                        mt-1
                                                        text-xs
                                                        text-[#8f99bb]
                                                    "
                                                >
                                                    Etapa:

                                                    <span
                                                        class="
                                                            text-[#c8cee4]
                                                        "
                                                    >
                                                        {{
                                                            $deal[
                                                                'stage_label'
                                                            ]
                                                            ?? $deal[
                                                                'stage_id'
                                                            ]
                                                            ?? '—'
                                                        }}
                                                    </span>
                                                </div>

                                            </div>


                                            <span
                                                class="
                                                    shrink-0
                                                    rounded-full
                                                    px-2.5 py-1
                                                    text-[10px]
                                                    font-bold
                                                    uppercase
                                                    {{
                                                        $dealStateClasses
                                                    }}
                                                "
                                            >
                                                {{
                                                    $dealState
                                                }}
                                            </span>

                                        </div>

                                    </div>

                                @endforeach

                            </div>

                        </div>

                    @endif


                    @if ($this->hubSpotCompanyUrl())

                        <div class="mt-5">

                            <a
                                href="{{ $this->hubSpotCompanyUrl() }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="ec-button-secondary"
                            >
                                Abrir no HubSpot

                                <span aria-hidden="true">
                                    ↗
                                </span>
                            </a>

                        </div>

                    @endif

                </div>

            </details>

        @endif


        @if ($company->icpScore)

            <details class="mt-4 overflow-hidden rounded-xl border border-white/5 bg-white/[0.025]">

                <summary
                    class="
                        flex cursor-pointer list-none
                        items-center justify-between
                        px-5 py-4
                        text-sm font-semibold
                        text-[#d9ddef]
                        transition
                        hover:bg-white/[0.025]
                    "
                >
                    <span>
                        Detalhamento do ICP
                    </span>

                    <span class="text-xs font-medium text-[#7f87a7]">
                        {{ $company->icpScore->grade }}
                        •
                        {{ $company->icpScore->score }}/100
                    </span>
                </summary>

                <div class="border-t border-white/5 px-5 py-4">

                    <div class="space-y-3">

                        @foreach ([
                            'cnae' => 'CNAE prioritário',
                            'state' => 'Estado prioritário',
                            'size' => 'Porte da empresa',
                            'capital' => 'Capital social',
                            'legal_nature' => 'Natureza jurídica',
                            'regional_relevance' => 'Relevância regional',
                        ] as $factorKey => $factorLabel)

                            @php
                                $factor = data_get(
                                    $company->icpScore->factors,
                                    $factorKey,
                                    []
                                );

                                $points = (int) (
                                    $factor['points']
                                    ?? 0
                                );

                                $max = (int) (
                                    $factor['max']
                                    ?? 0
                                );

                                $reason =
                                    $factor['reason']
                                    ?? 'Sem informação.';
                            @endphp

                            <div
                                class="
                                    grid gap-3
                                    rounded-lg
                                    border border-white/5
                                    bg-black/5
                                    px-4 py-3
                                    md:grid-cols-[180px_1fr_80px]
                                    md:items-center
                                "
                            >

                                <div class="text-sm font-medium text-[#d9ddef]">
                                    {{ $factorLabel }}
                                </div>

                                <div class="text-xs text-[#838baa]">
                                    {{ $reason }}
                                </div>

                                <div class="text-right">

                                    <span
                                        class="
                                            inline-flex rounded-full
                                            px-2.5 py-1
                                            text-xs font-bold
                                            {{ $points > 0
                                                ? 'bg-emerald-500/10 text-emerald-300'
                                                : 'bg-white/5 text-[#747c9b]'
                                            }}
                                        "
                                    >
                                        +{{ $points }}/{{ $max }}
                                    </span>

                                </div>

                            </div>

                        @endforeach

                    </div>

                    <div
                        class="
                            mt-4 flex items-center
                            justify-between
                            border-t border-white/5
                            pt-4
                        "
                    >

                        <div>

                            <div class="text-xs uppercase tracking-[0.12em] text-[#737b9c]">
                                Classificação
                            </div>

                            <div class="mt-1 text-sm font-semibold text-white">
                                {{ $company->icpScore->label }}
                            </div>

                        </div>

                        <div class="text-right">

                            <div class="text-xs text-[#737b9c]">
                                Score cadastral
                            </div>

                            <div class="mt-1 text-2xl font-bold text-[#43b9a7]">
                                {{ $company->icpScore->score }}
                            </div>

                        </div>

                    </div>

                    <p class="mt-4 text-xs leading-5 text-[#68708f]">
                        Este score considera somente dados cadastrais e estruturais.
                        Exportação, relação com tradings, CRM e contatos serão avaliados
                        em etapas posteriores do Prospector.
                    </p>

                </div>

            </details>

        @endif

    </section>


    {{-- PRESENÇA OPERACIONAL DO GRUPO --}}
    @php
        $operational =
            $this
                ->groupOperationalSummary;
    @endphp

    <section class="ec-detail-panel">

        <div class="ec-detail-header">

            <div>

                <h2 class="ec-detail-title">
                    Presença operacional do grupo
                </h2>

                <p class="ec-detail-description">
                    Distribuição das unidades cadastradas
                    e da operação ativa do grupo.
                </p>

            </div>

        </div>


        <div
            class="
                grid gap-3
                sm:grid-cols-2
                xl:grid-cols-4
            "
        >

            <div
                class="
                    rounded-xl
                    border border-white/[0.06]
                    bg-white/[0.025]
                    p-4
                "
                data-operational-total="{{ $operational['total'] }}"
            >

                <div class="ec-intelligence-label">
                    Unidades cadastradas
                </div>

                <div class="ec-score-value">
                    {{ $operational['total'] }}
                </div>

                <div class="ec-intelligence-caption">
                    Matriz + filiais
                </div>

            </div>


            <div
                class="
                    rounded-xl
                    border border-emerald-400/10
                    bg-emerald-400/[0.025]
                    p-4
                "
                data-operational-active="{{ $operational['active'] }}"
            >

                <div class="ec-intelligence-label">
                    Unidades ativas
                </div>

                <div
                    class="
                        mt-2 text-2xl
                        font-bold
                        text-emerald-300
                    "
                >
                    {{ $operational['active'] }}
                </div>

                <div class="ec-intelligence-caption">
                    Operação cadastrada como ativa
                </div>

            </div>


            <div
                class="
                    rounded-xl
                    border border-white/[0.06]
                    bg-white/[0.025]
                    p-4
                "
                data-operational-states="{{ $operational['active_states_count'] }}"
            >

                <div class="ec-intelligence-label">
                    Estados ativos
                </div>

                <div class="ec-score-value">
                    {{
                        $operational[
                            'active_states_count'
                        ]
                    }}
                </div>

                <div class="ec-intelligence-caption">
                    Presença geográfica ativa
                </div>

            </div>


            <div
                class="
                    rounded-xl
                    border border-white/[0.06]
                    bg-white/[0.025]
                    p-4
                "
                data-operational-cities="{{ $operational['active_municipalities_count'] }}"
            >

                <div class="ec-intelligence-label">
                    Municípios ativos
                </div>

                <div class="ec-score-value">
                    {{
                        $operational[
                            'active_municipalities_count'
                        ]
                    }}
                </div>

                <div class="ec-intelligence-caption">
                    Municípios com unidade ativa
                </div>

            </div>

        </div>


        <div
            class="
                mt-4 grid gap-4
                lg:grid-cols-2
            "
        >

            {{-- ESTADOS ATIVOS --}}
            <div
                class="
                    rounded-xl
                    border border-white/[0.05]
                    bg-white/[0.02]
                    p-4
                "
            >

                <div
                    class="
                        text-[10px]
                        font-semibold
                        uppercase
                        tracking-[0.12em]
                        text-[#737d9e]
                    "
                >
                    Estados com operação ativa
                </div>

                @if (
                    $operational[
                        'states'
                    ] !== []
                )

                    <div
                        class="
                            mt-3 flex
                            flex-wrap gap-2
                        "
                    >

                        @foreach (
                            $operational[
                                'states'
                            ]
                            as $stateItem
                        )

                            <span
                                class="
                                    rounded-full
                                    bg-cyan-400/10
                                    px-2.5 py-1
                                    text-[10px]
                                    font-semibold
                                    text-cyan-300
                                "
                            >
                                {{ $stateItem['state'] }}
                                ·
                                {{ $stateItem['count'] }}
                            </span>

                        @endforeach

                    </div>

                @else

                    <div
                        class="
                            mt-3 text-xs
                            text-[#697394]
                        "
                    >
                        Nenhum estado ativo identificado.
                    </div>

                @endif

            </div>


            {{-- SITUAÇÕES CADASTRAIS --}}
            <div
                class="
                    rounded-xl
                    border border-white/[0.05]
                    bg-white/[0.02]
                    p-4
                "
            >

                <div
                    class="
                        text-[10px]
                        font-semibold
                        uppercase
                        tracking-[0.12em]
                        text-[#737d9e]
                    "
                >
                    Situação cadastral das unidades
                </div>

                <div
                    class="
                        mt-3 flex
                        flex-wrap gap-2
                    "
                >

                    @foreach (
                        $operational[
                            'statuses'
                        ]
                        as $statusItem
                    )

                        @php
                            $statusName =
                                $statusItem[
                                    'status'
                                ];

                            $statusClasses =
                                match ($statusName) {
                                    'ATIVA' =>
                                        'bg-emerald-500/10 text-emerald-300',

                                    'SUSPENSA' =>
                                        'bg-amber-500/10 text-amber-300',

                                    'BAIXADA',
                                    'INAPTA',
                                    'NULA' =>
                                        'bg-rose-500/10 text-rose-300',

                                    default =>
                                        'bg-white/5 text-[#929bb9]',
                                };
                        @endphp

                        <span
                            class="
                                rounded-full
                                px-2.5 py-1
                                text-[10px]
                                font-semibold
                                {{ $statusClasses }}
                            "
                        >
                            {{ $statusName }}
                            ·
                            {{ $statusItem['count'] }}
                        </span>

                    @endforeach

                </div>

            </div>

        </div>

    </section>


    {{-- DADOS + CONTATO --}}
    <div class="ec-dossier-main-grid">

        {{-- DADOS CADASTRAIS --}}
        <section class="ec-detail-panel ec-dossier-data-panel">

            <div class="ec-detail-header">

                <div>

                    <h2 class="ec-detail-title">
                        Dados cadastrais
                    </h2>

                    <p class="ec-detail-description">
                        Informações oficiais e cadastrais da empresa.
                    </p>

                </div>

            </div>


            <dl class="ec-data-grid">

                <div class="ec-data-item">

                    <dt>
                        Razão social
                    </dt>

                    <dd>
                        {{ $company->corporate_name }}
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Nome fantasia
                    </dt>

                    <dd>
                        {{ $this->matrix?->fantasy_name ?: '—' }}
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        CNPJ raiz
                    </dt>

                    <dd class="font-mono">
                        {{ $company->cnpj_root }}
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Capital social
                    </dt>

                    <dd>
                        {{ $this->formatMoney($company->share_capital) }}
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Porte
                    </dt>

                    <dd>

                        {{ $company->size_code ?: '—' }}

                        @if ($company->size_description)

                            <span class="ec-data-muted">
                                — {{ $company->size_description }}
                            </span>

                        @endif

                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Natureza jurídica
                    </dt>

                    <dd>

                        {{ $company->legal_nature_code ?: '—' }}

                        @if ($company->legal_nature_description)

                            <span class="ec-data-muted">
                                — {{ $company->legal_nature_description }}
                            </span>

                        @endif

                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Origem
                    </dt>

                    <dd>
                        <span class="ec-source-badge">
                            {{ ucfirst($company->source) }}
                        </span>
                    </dd>

                </div>


                <div class="ec-data-item">

                    <dt>
                        Última atualização
                    </dt>

                    <dd>
                        {{
                            $company
                                ->source_updated_at
                                ?->format('d/m/Y H:i')
                            ?? '—'
                        }}
                    </dd>

                </div>

            </dl>

        </section>


        {{-- CONTATOS CADASTRAIS DO GRUPO --}}
        <section class="ec-detail-panel">

            <div class="ec-detail-header">

                <div>

                    <div
                        class="
                            flex flex-wrap
                            items-center gap-2
                        "
                    >

                        <h2 class="ec-detail-title">
                            Contatos cadastrais do grupo
                        </h2>

                        <span class="ec-count-badge">
                            {{
                                $this
                                    ->contactEstablishments
                                    ->count()
                            }}
                        </span>

                    </div>

                    <p class="ec-detail-description">
                        E-mails e telefones públicos da matriz e filiais.
                    </p>

                </div>

            </div>


            @php
                $groupContacts =
                    $this
                        ->groupContactSummary;
            @endphp

            {{-- CONTATOS ÚNICOS DO GRUPO --}}
            @if (
                $groupContacts[
                    'emails'
                ] !== []
                || $groupContacts[
                    'phones'
                ] !== []
            )

                <div
                    class="
                        mb-4 rounded-xl
                        border border-cyan-300/10
                        bg-cyan-300/[0.025]
                        p-4
                    "
                >

                    <div
                        class="
                            flex flex-col gap-3
                            sm:flex-row
                            sm:items-center
                            sm:justify-between
                        "
                    >

                        <div>

                            <div
                                class="
                                    text-sm
                                    font-semibold
                                    text-[#eef1ff]
                                "
                            >
                                Contatos únicos do grupo
                            </div>

                            <div
                                class="
                                    mt-0.5 text-xs
                                    text-[#7983a4]
                                "
                            >
                                Contatos repetidos entre
                                filiais são consolidados.
                            </div>

                        </div>


                        <div
                            class="
                                flex flex-wrap
                                gap-2
                                text-[10px]
                                font-semibold
                                uppercase
                            "
                        >

                            <span
                                class="
                                    rounded-full
                                    bg-white/5
                                    px-2.5 py-1
                                    text-[#9da6c5]
                                "
                            >
                                {{
                                    $groupContacts[
                                        'units_with_contact'
                                    ]
                                }}
                                unidade(s)
                            </span>

                            <span
                                class="
                                    rounded-full
                                    bg-cyan-400/10
                                    px-2.5 py-1
                                    text-cyan-300
                                "
                            >
                                {{ count($groupContacts['emails']) }} e-mail(s) único(s)
                            </span>

                            <span
                                class="
                                    rounded-full
                                    bg-emerald-400/10
                                    px-2.5 py-1
                                    text-emerald-300
                                "
                            >
                                {{ count($groupContacts['phones']) }} telefone(s) único(s)
                            </span>

                        </div>

                    </div>


                    <div
                        class="
                            mt-4 grid gap-3
                            lg:grid-cols-2
                        "
                    >

                        {{-- E-MAILS ÚNICOS --}}
                        <div
                            class="
                                rounded-xl
                                border border-white/[0.05]
                                bg-white/[0.02]
                                p-3
                            "
                        >

                            <div
                                class="
                                    text-[10px]
                                    font-semibold
                                    uppercase
                                    tracking-[0.12em]
                                    text-[#737d9e]
                                "
                            >
                                E-mails
                            </div>

                            @if (
                                $groupContacts[
                                    'emails'
                                ] !== []
                            )

                                <div
                                    class="
                                        mt-3 max-h-60
                                        space-y-3
                                        overflow-y-auto
                                        pr-1
                                    "
                                >

                                    @foreach (
                                        $groupContacts[
                                            'emails'
                                        ]
                                        as $emailContact
                                    )

                                        <div>

                                            <div
                                                class="
                                                    flex
                                                    items-start
                                                    justify-between
                                                    gap-2
                                                "
                                            >

                                                <a
                                                    href="mailto:{{
                                                        $emailContact[
                                                            'value'
                                                        ]
                                                    }}"
                                                    class="
                                                        min-w-0
                                                        break-all
                                                        text-xs
                                                        font-semibold
                                                        text-cyan-300
                                                        hover:text-cyan-200
                                                    "
                                                >
                                                    {{
                                                        $emailContact[
                                                            'value'
                                                        ]
                                                    }}
                                                </a>

                                                <span
                                                    class="
                                                        shrink-0
                                                        rounded-full
                                                        bg-white/5
                                                        px-2 py-0.5
                                                        text-[9px]
                                                        text-[#858fad]
                                                    "
                                                >
                                                    {{ $emailContact['count'] }} unidade(s)
                                                </span>

                                            </div>


                                            <div
                                                class="
                                                    mt-1
                                                    text-[10px]
                                                    leading-4
                                                    text-[#687394]
                                                "
                                                title="{{
                                                    implode(
                                                        ' | ',
                                                        $emailContact[
                                                            'locations'
                                                        ]
                                                    )
                                                }}"
                                            >

                                                {{
                                                    implode(
                                                        ' · ',
                                                        array_slice(
                                                            $emailContact[
                                                                'locations'
                                                            ],
                                                            0,
                                                            2
                                                        )
                                                    )
                                                }}

                                                @if (
                                                    $emailContact[
                                                        'count'
                                                    ] > 2
                                                )

                                                    · +{{
                                                        $emailContact[
                                                            'count'
                                                        ] - 2
                                                    }}
                                                    unidade(s)

                                                @endif

                                            </div>

                                        </div>

                                    @endforeach

                                </div>

                            @else

                                <div
                                    class="
                                        mt-3 text-xs
                                        text-[#66708f]
                                    "
                                >
                                    Nenhum e-mail encontrado.
                                </div>

                            @endif

                        </div>


                        {{-- TELEFONES ÚNICOS --}}
                        <div
                            class="
                                rounded-xl
                                border border-white/[0.05]
                                bg-white/[0.02]
                                p-3
                            "
                        >

                            <div
                                class="
                                    text-[10px]
                                    font-semibold
                                    uppercase
                                    tracking-[0.12em]
                                    text-[#737d9e]
                                "
                            >
                                Telefones
                            </div>

                            @if (
                                $groupContacts[
                                    'phones'
                                ] !== []
                            )

                                <div
                                    class="
                                        mt-3 max-h-60
                                        space-y-3
                                        overflow-y-auto
                                        pr-1
                                    "
                                >

                                    @foreach (
                                        $groupContacts[
                                            'phones'
                                        ]
                                        as $phoneContact
                                    )

                                        <div>

                                            <div
                                                class="
                                                    flex
                                                    items-start
                                                    justify-between
                                                    gap-2
                                                "
                                            >

                                                <a
                                                    href="{{
                                                        $phoneContact[
                                                            'href'
                                                        ]
                                                    }}"
                                                    class="
                                                        text-xs
                                                        font-semibold
                                                        text-emerald-300
                                                        hover:text-emerald-200
                                                    "
                                                >
                                                    {{
                                                        $phoneContact[
                                                            'value'
                                                        ]
                                                    }}
                                                </a>

                                                <span
                                                    class="
                                                        shrink-0
                                                        rounded-full
                                                        bg-white/5
                                                        px-2 py-0.5
                                                        text-[9px]
                                                        text-[#858fad]
                                                    "
                                                >
                                                    {{ $phoneContact['count'] }} unidade(s)
                                                </span>

                                            </div>


                                            <div
                                                class="
                                                    mt-1
                                                    text-[10px]
                                                    leading-4
                                                    text-[#687394]
                                                "
                                                title="{{
                                                    implode(
                                                        ' | ',
                                                        $phoneContact[
                                                            'locations'
                                                        ]
                                                    )
                                                }}"
                                            >

                                                {{
                                                    implode(
                                                        ' · ',
                                                        array_slice(
                                                            $phoneContact[
                                                                'locations'
                                                            ],
                                                            0,
                                                            2
                                                        )
                                                    )
                                                }}

                                                @if (
                                                    $phoneContact[
                                                        'count'
                                                    ] > 2
                                                )

                                                    · +{{
                                                        $phoneContact[
                                                            'count'
                                                        ] - 2
                                                    }}
                                                    unidade(s)

                                                @endif

                                            </div>

                                        </div>

                                    @endforeach

                                </div>

                            @else

                                <div
                                    class="
                                        mt-3 text-xs
                                        text-[#66708f]
                                    "
                                >
                                    Nenhum telefone encontrado.
                                </div>

                            @endif

                        </div>

                    </div>

                </div>

            @endif


            @if (
                $this
                    ->contactEstablishments
                    ->isNotEmpty()
            )

                <div
                    class="
                        max-h-[430px]
                        space-y-3
                        overflow-y-auto
                        pr-1
                    "
                >

                    @foreach (
                        $this
                            ->contactEstablishments
                        as $contactEstablishment
                    )

                        <div
                            wire:key="contact-establishment-{{
                                $contactEstablishment->id
                            }}"
                            class="
                                rounded-xl
                                border
                                border-white/[0.06]
                                bg-white/[0.025]
                                p-4
                            "
                        >

                            <div
                                class="
                                    flex flex-wrap
                                    items-start
                                    justify-between
                                    gap-2
                                "
                            >

                                <div>

                                    <div
                                        class="
                                            flex flex-wrap
                                            items-center
                                            gap-2
                                        "
                                    >

                                        <span
                                            class="
                                                text-xs
                                                font-semibold
                                                text-[#eef1ff]
                                            "
                                        >
                                            {{
                                                $contactEstablishment
                                                    ->type
                                                    === 'matrix'
                                                    ? 'Matriz'
                                                    : 'Filial'
                                            }}
                                        </span>

                                        @if (
                                            $contactEstablishment
                                                ->registration_status
                                        )

                                            <span
                                                class="
                                                    rounded-full
                                                    px-2 py-0.5
                                                    text-[9px]
                                                    font-bold
                                                    uppercase
                                                    {{
                                                        $contactEstablishment
                                                            ->registration_status
                                                            === 'ATIVA'
                                                            ? 'bg-emerald-500/10 text-emerald-300'
                                                            : 'bg-white/5 text-[#7f87a7]'
                                                    }}
                                                "
                                            >
                                                {{
                                                    $contactEstablishment
                                                        ->registration_status
                                                }}
                                            </span>

                                        @endif

                                    </div>


                                    <div
                                        class="
                                            mt-1 text-[11px]
                                            text-[#737d9e]
                                        "
                                    >

                                        {{
                                            Cnpj::format(
                                                $contactEstablishment
                                                    ->cnpj
                                            )
                                        }}

                                        @if (
                                            $contactEstablishment
                                                ->municipality_name
                                        )

                                            ·

                                            {{
                                                $contactEstablishment
                                                    ->municipality_name
                                            }}

                                            @if (
                                                $contactEstablishment
                                                    ->state
                                            )
                                                /
                                                {{
                                                    $contactEstablishment
                                                        ->state
                                                }}
                                            @endif

                                        @endif

                                    </div>

                                </div>

                            </div>


                            <div
                                class="
                                    mt-3 grid gap-3
                                    sm:grid-cols-2
                                "
                            >

                                <div>

                                    <div class="ec-contact-label">
                                        E-mail
                                    </div>

                                    <div
                                        class="
                                            mt-1 break-all
                                            text-xs
                                            text-[#d9ddef]
                                        "
                                    >

                                        @if (
                                            $contactEstablishment
                                                ->email
                                        )

                                            <a
                                                href="mailto:{{
                                                    $contactEstablishment
                                                        ->email
                                                }}"
                                                class="
                                                    text-cyan-300
                                                    hover:text-cyan-200
                                                "
                                            >
                                                {{
                                                    $contactEstablishment
                                                        ->email
                                                }}
                                            </a>

                                        @else
                                            —
                                        @endif

                                    </div>

                                </div>


                                <div>

                                    <div class="ec-contact-label">
                                        Telefone(s)
                                    </div>

                                    <div
                                        class="
                                            mt-1 flex
                                            flex-col gap-1
                                            text-xs
                                            text-[#d9ddef]
                                        "
                                    >

                                        @if (
                                            $contactEstablishment
                                                ->phone_1
                                        )

                                            <a
                                                href="{{
                                                    $this
                                                        ->phoneHref(
                                                            $contactEstablishment
                                                                ->phone_1
                                                        )
                                                }}"
                                                class="
                                                    hover:text-cyan-300
                                                "
                                            >
                                                {{
                                                    $this
                                                        ->formatPhone(
                                                            $contactEstablishment
                                                                ->phone_1
                                                        )
                                                }}
                                            </a>

                                        @endif


                                        @if (
                                            $contactEstablishment
                                                ->phone_2
                                        )

                                            <a
                                                href="{{
                                                    $this
                                                        ->phoneHref(
                                                            $contactEstablishment
                                                                ->phone_2
                                                        )
                                                }}"
                                                class="
                                                    hover:text-cyan-300
                                                "
                                            >
                                                {{
                                                    $this
                                                        ->formatPhone(
                                                            $contactEstablishment
                                                                ->phone_2
                                                        )
                                                }}
                                            </a>

                                        @endif


                                        @if (
                                            ! $contactEstablishment
                                                ->phone_1
                                            && ! $contactEstablishment
                                                ->phone_2
                                        )
                                            —
                                        @endif

                                    </div>

                                </div>

                            </div>

                        </div>

                    @endforeach

                </div>

            @else

                <div
                    class="
                        rounded-xl
                        border border-white/[0.05]
                        bg-white/[0.02]
                        px-4 py-6
                        text-center
                    "
                >

                    <div
                        class="
                            text-sm
                            font-semibold
                            text-[#b5bdd7]
                        "
                    >
                        Nenhum contato público encontrado
                    </div>

                    <div
                        class="
                            mt-1 text-xs
                            text-[#707a9d]
                        "
                    >
                        A Receita não possui e-mail ou telefone
                        cadastrado nas unidades deste grupo.
                    </div>

                </div>

            @endif


            <div class="ec-contact-note">
                Estes contatos são dados cadastrais públicos.
                Eles ainda não representam necessariamente
                um decisor comercial.
            </div>

        </section>

    </div>


    {{-- ESTABELECIMENTOS --}}
    <section class="ec-table-panel">

        <div class="ec-table-toolbar">

            <div>

                <div class="flex items-center gap-2">

                    <h2 class="ec-table-title">
                        Estabelecimentos
                    </h2>

                    <span class="ec-count-badge">
                        {{ $company->establishments->count() }}
                    </span>

                </div>

                <p class="ec-table-description">
                    Matriz e filiais vinculadas ao mesmo CNPJ raiz.
                </p>

            </div>

            <a
                href="{{ route('companies.branches.create', $company) }}"
                wire:navigate
                class="ec-button-primary"
            >

                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    class="size-4"
                >
                    <path d="M12 5v14M5 12h14" />
                </svg>

                Adicionar filial

            </a>

        </div>


        <div class="overflow-x-auto">

            <table class="ec-table">

                <thead>

                    <tr>

                        <th>
                            Tipo
                        </th>

                        <th>
                            CNPJ
                        </th>

                        <th>
                            Nome fantasia
                        </th>

                        <th>
                            Localização
                        </th>

                        <th>
                            Contato
                        </th>

                        <th>
                            Situação
                        </th>

                        <th>
                            Cadastro
                        </th>

                    </tr>

                </thead>

                <tbody>

                    @forelse (
                        $company->establishments
                        as $establishment
                    )

                        <tr
                            wire:key="establishment-{{ $establishment->id }}"
                        >

                            <td>

                                @if ($establishment->type === 'matrix')

                                    <span class="ec-type-badge ec-type-matrix">
                                        Matriz
                                    </span>

                                @else

                                    <span class="ec-type-badge ec-type-branch">
                                        Filial
                                    </span>

                                @endif

                            </td>


                            <td class="whitespace-nowrap">

                                <span class="ec-table-primary-text font-mono">
                                    {{ Cnpj::format($establishment->cnpj) }}
                                </span>

                            </td>


                            <td>

                                <span class="ec-table-primary-text">
                                    {{ $establishment->fantasy_name ?: '—' }}
                                </span>

                            </td>


                            <td>

                                <div
                                    class="
                                        min-w-[280px]
                                        max-w-[440px]
                                    "
                                >

                                    <div
                                        class="
                                            text-xs
                                            leading-5
                                            text-[#c8cee3]
                                        "
                                    >
                                        {{
                                            $this
                                                ->establishmentAddress(
                                                    $establishment
                                                )
                                        }}
                                    </div>

                                </div>

                            </td>


                            <td>

                                <div
                                    class="
                                        min-w-[220px]
                                        space-y-1
                                    "
                                >

                                    @if (
                                        $establishment
                                            ->email
                                    )

                                        <a
                                            href="mailto:{{
                                                $establishment
                                                    ->email
                                            }}"
                                            class="
                                                block
                                                truncate
                                                text-xs
                                                text-cyan-300
                                                hover:text-cyan-200
                                            "
                                            title="{{
                                                $establishment
                                                    ->email
                                            }}"
                                        >
                                            {{
                                                $establishment
                                                    ->email
                                            }}
                                        </a>

                                    @endif


                                    @if (
                                        $establishment
                                            ->phone_1
                                    )

                                        <a
                                            href="{{
                                                $this
                                                    ->phoneHref(
                                                        $establishment
                                                            ->phone_1
                                                    )
                                            }}"
                                            class="
                                                block text-xs
                                                text-[#a8b0ce]
                                                hover:text-cyan-300
                                            "
                                        >
                                            {{
                                                $this
                                                    ->formatPhone(
                                                        $establishment
                                                            ->phone_1
                                                    )
                                            }}
                                        </a>

                                    @endif


                                    @if (
                                        $establishment
                                            ->phone_2
                                    )

                                        <a
                                            href="{{
                                                $this
                                                    ->phoneHref(
                                                        $establishment
                                                            ->phone_2
                                                    )
                                            }}"
                                            class="
                                                block text-xs
                                                text-[#a8b0ce]
                                                hover:text-cyan-300
                                            "
                                        >
                                            {{
                                                $this
                                                    ->formatPhone(
                                                        $establishment
                                                            ->phone_2
                                                    )
                                            }}
                                        </a>

                                    @endif


                                    @if (
                                        ! $establishment
                                            ->email
                                        && ! $establishment
                                            ->phone_1
                                        && ! $establishment
                                            ->phone_2
                                    )

                                        <span class="ec-table-muted">
                                            —
                                        </span>

                                    @endif

                                </div>

                            </td>


                            <td class="whitespace-nowrap">

                                @if (
                                    $establishment->registration_status
                                    === 'ATIVA'
                                )

                                    <span class="ec-status ec-status-active">
                                        <span></span>
                                        Ativa
                                    </span>

                                @elseif (
                                    $establishment->registration_status
                                    === 'SUSPENSA'
                                )

                                    <span class="ec-status ec-status-warning">
                                        <span></span>
                                        Suspensa
                                    </span>

                                @elseif (
                                    $establishment->registration_status
                                )

                                    <span class="ec-status ec-status-inactive">

                                        <span></span>

                                        {{
                                            ucfirst(
                                                mb_strtolower(
                                                    $establishment
                                                        ->registration_status
                                                )
                                            )
                                        }}

                                    </span>

                                @else

                                    <span class="ec-table-muted">
                                        —
                                    </span>

                                @endif

                            </td>


                            <td
                                data-establishment-registration-details
                            >

                                <div
                                    class="
                                        min-w-[230px]
                                        space-y-2
                                        text-xs
                                    "
                                >

                                    <div>

                                        <span
                                            class="
                                                text-[#737d9e]
                                            "
                                        >
                                            Abertura:
                                        </span>

                                        <span
                                            class="
                                                ml-1
                                                text-[#c8cee3]
                                            "
                                        >
                                            {{
                                                $this
                                                    ->formatEstablishmentDate(
                                                        $establishment
                                                            ->start_date
                                                    )
                                            }}
                                        </span>

                                    </div>


                                    <div>

                                        <span
                                            class="
                                                text-[#737d9e]
                                            "
                                        >
                                            Situação desde:
                                        </span>

                                        <span
                                            class="
                                                ml-1
                                                text-[#c8cee3]
                                            "
                                        >
                                            {{
                                                $this
                                                    ->formatEstablishmentDate(
                                                        $establishment
                                                            ->registration_status_date
                                                    )
                                            }}
                                        </span>

                                    </div>


                                    @if (
                                        $establishment
                                            ->registration_status_reason_code
                                    )

                                        <div>

                                            <span
                                                class="
                                                    text-[#737d9e]
                                                "
                                            >
                                                Motivo:
                                            </span>

                                            <span
                                                class="
                                                    ml-1
                                                    font-mono
                                                    text-[#aeb6d3]
                                                "
                                            >
                                                {{
                                                    $establishment
                                                        ->registration_status_reason_code
                                                }}
                                            </span>

                                        </div>

                                    @endif


                                    @if (
                                        $establishment
                                            ->special_situation
                                    )

                                        <div
                                            class="
                                                rounded-lg
                                                border
                                                border-amber-400/10
                                                bg-amber-400/[0.025]
                                                px-2.5 py-2
                                            "
                                        >

                                            <div
                                                class="
                                                    text-[10px]
                                                    font-semibold
                                                    uppercase
                                                    tracking-wide
                                                    text-amber-300
                                                "
                                            >
                                                Situação especial
                                            </div>

                                            <div
                                                class="
                                                    mt-1
                                                    text-xs
                                                    font-medium
                                                    text-[#d9ddef]
                                                "
                                            >
                                                {{
                                                    $establishment
                                                        ->special_situation
                                                }}
                                            </div>

                                            @if (
                                                $establishment
                                                    ->special_situation_date
                                            )

                                                <div
                                                    class="
                                                        mt-1
                                                        text-[10px]
                                                        text-[#8d96b6]
                                                    "
                                                >
                                                    Desde
                                                    {{
                                                        $this
                                                            ->formatEstablishmentDate(
                                                                $establishment
                                                                    ->special_situation_date
                                                            )
                                                    }}
                                                </div>

                                            @endif

                                        </div>

                                    @endif


                                    @if (
                                        $establishment
                                            ->source_updated_at
                                    )

                                        <div
                                            class="
                                                pt-1
                                                text-[10px]
                                                text-[#626c8d]
                                            "
                                        >
                                            Fonte atualizada em
                                            {{
                                                $this
                                                    ->formatEstablishmentDate(
                                                        $establishment
                                                            ->source_updated_at
                                                    )
                                            }}
                                        </div>

                                    @endif

                                </div>

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td
                                colspan="7"
                                class="!py-16 text-center"
                            >

                                <div class="ec-empty-title">
                                    Nenhum estabelecimento encontrado
                                </div>

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>

    </section>


    {{-- CNAES DO GRUPO --}}
    <section class="ec-detail-panel">

        <div class="ec-detail-header">

            <div>

                <div
                    class="
                        flex flex-wrap
                        items-center gap-2
                    "
                >

                    <h2 class="ec-detail-title">
                        CNAEs do grupo
                    </h2>

                    <span class="ec-count-badge">
                        {{
                            $this
                                ->groupCnaes
                                ->count()
                        }}
                    </span>

                </div>

                <p class="ec-detail-description">
                    Atividades econômicas encontradas
                    na matriz e nas filiais do grupo.
                </p>

            </div>

        </div>


        @if (
            $this
                ->groupCnaes
                ->isNotEmpty()
        )

            <div
                class="
                    grid gap-3
                    md:grid-cols-2
                    xl:grid-cols-3
                "
            >

                @foreach (
                    $this->groupCnaes
                    as $groupCnae
                )

                    <div
                        wire:key="group-cnae-{{
                            $groupCnae['code']
                        }}"
                        class="
                            rounded-xl
                            border border-white/[0.06]
                            bg-white/[0.025]
                            p-4
                        "
                    >

                        <div
                            class="
                                flex items-start
                                justify-between
                                gap-3
                            "
                        >

                            <div
                                class="
                                    font-mono
                                    text-sm
                                    font-bold
                                    text-cyan-300
                                "
                            >
                                {{
                                    $groupCnae[
                                        'code'
                                    ]
                                }}
                            </div>


                            @if (
                                $groupCnae[
                                    'primary_units_count'
                                ] > 0
                            )

                                <span
                                    class="
                                        rounded-full
                                        bg-emerald-500/10
                                        px-2 py-1
                                        text-[9px]
                                        font-bold
                                        uppercase
                                        text-emerald-300
                                    "
                                >
                                    Principal em
                                    {{
                                        $groupCnae[
                                            'primary_units_count'
                                        ]
                                    }}
                                </span>

                            @endif

                        </div>


                        <div
                            class="
                                mt-2 min-h-10
                                text-xs
                                leading-5
                                text-[#c8cee3]
                            "
                        >
                            {{
                                $groupCnae[
                                    'description'
                                ]
                                ?: 'Sem descrição'
                            }}
                        </div>


                        <div
                            class="
                                mt-3 flex
                                flex-wrap gap-2
                            "
                        >

                            <span
                                class="
                                    rounded-full
                                    bg-white/5
                                    px-2 py-1
                                    text-[10px]
                                    font-semibold
                                    text-[#9da6c5]
                                "
                            >
                                {{ $groupCnae['units_count'] }} unidade(s)
                            </span>

                            <span
                                class="
                                    rounded-full
                                    bg-emerald-400/10
                                    px-2 py-1
                                    text-[10px]
                                    font-semibold
                                    text-emerald-300
                                "
                            >
                                {{ $groupCnae['active_units_count'] }} ativa(s)
                            </span>

                        </div>


                        @if (
                            $groupCnae[
                                'locations'
                            ] !== []
                        )

                            <div
                                class="
                                    mt-3 border-t
                                    border-white/5
                                    pt-3 text-[10px]
                                    leading-4
                                    text-[#687394]
                                "
                                title="{{
                                    implode(
                                        ' | ',
                                        $groupCnae[
                                            'locations'
                                        ]
                                    )
                                }}"
                            >

                                {{
                                    implode(
                                        ' · ',
                                        array_slice(
                                            $groupCnae[
                                                'locations'
                                            ],
                                            0,
                                            3
                                        )
                                    )
                                }}

                                @if (
                                    $groupCnae[
                                        'units_count'
                                    ] > 3
                                )

                                    · +{{
                                        $groupCnae[
                                            'units_count'
                                        ] - 3
                                    }}
                                    unidade(s)

                                @endif

                            </div>

                        @endif

                    </div>

                @endforeach

            </div>

        @else

            <div
                class="
                    rounded-xl
                    border border-white/[0.05]
                    bg-white/[0.02]
                    px-4 py-8
                    text-center
                "
            >

                <div
                    class="
                        text-sm font-semibold
                        text-[#b5bdd7]
                    "
                >
                    Nenhum CNAE encontrado no grupo
                </div>

            </div>

        @endif

    </section>


    {{-- CNAES --}}
    <section class="ec-detail-panel ec-cnae-panel">

        <div class="ec-detail-header ec-cnae-header">

            <div>

                <h2 class="ec-detail-title">
                    CNAEs da matriz
                </h2>

                <p class="ec-detail-description">
                    Cadastro manual dos CNAEs específicos da matriz.
                </p>

            </div>

            <button
                type="button"
                wire:click="toggleCnaeForm"
                class="{{
                    $showCnaeForm
                        ? 'ec-button-secondary'
                        : 'ec-button-primary'
                }}"
            >

                @if ($showCnaeForm)

                    Cancelar

                @else

                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        class="size-4"
                    >
                        <path d="M12 5v14M5 12h14" />
                    </svg>

                    Adicionar CNAE

                @endif

            </button>

        </div>


        {{-- FORMULÁRIO CNAE --}}
        @if ($showCnaeForm)

            <div class="ec-cnae-form">

                <form
                    wire:submit="addCnae"
                    class="space-y-4"
                >

                    <div class="grid gap-4 md:grid-cols-3">

                        <div>

                            <label
                                for="newCnaeCode"
                                class="ec-field-label"
                            >
                                Código CNAE
                            </label>

                            <input
                                id="newCnaeCode"
                                wire:model.blur="newCnaeCode"
                                placeholder="4622200"
                                maxlength="20"
                                class="ec-input"
                            >

                            @error('newCnaeCode')

                                <p class="ec-field-error">
                                    {{ $message }}
                                </p>

                            @enderror

                        </div>


                        <div class="md:col-span-2">

                            <label
                                for="newCnaeDescription"
                                class="ec-field-label"
                            >
                                Descrição
                            </label>

                            <input
                                id="newCnaeDescription"
                                wire:model.blur="newCnaeDescription"
                                placeholder="Ex.: Comércio atacadista de soja"
                                class="ec-input"
                            >

                            @error('newCnaeDescription')

                                <p class="ec-field-error">
                                    {{ $message }}
                                </p>

                            @enderror

                        </div>

                    </div>


                    <label class="ec-checkbox-row">

                        <input
                            type="checkbox"
                            wire:model="newCnaePrimary"
                            class="ec-checkbox"
                        >

                        <span>

                            <strong>
                                CNAE principal
                            </strong>

                            <small>
                                Definir esta atividade como principal da matriz.
                            </small>

                        </span>

                    </label>


                    <div class="flex justify-end">

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="addCnae"
                            class="ec-button-primary"
                        >

                            <span
                                wire:loading.remove
                                wire:target="addCnae"
                            >
                                Salvar CNAE
                            </span>

                            <span
                                wire:loading
                                wire:target="addCnae"
                            >
                                Salvando...
                            </span>

                        </button>

                    </div>

                </form>

            </div>

        @endif


        <div class="ec-cnae-content">

            {{-- PRINCIPAL --}}
            @if ($this->primaryCnae)

                <div class="ec-cnae-block">

                    <div class="ec-cnae-block-label">
                        CNAE principal
                    </div>

                    <div class="ec-cnae-primary">

                        <div>

                            <div class="flex flex-wrap items-center gap-2">

                                <span class="ec-cnae-main-code">
                                    {{ $this->primaryCnae->code }}
                                </span>

                                <span class="ec-primary-badge">
                                    Principal
                                </span>

                            </div>

                            <p class="ec-cnae-main-description">
                                {{
                                    $this->primaryCnae->description
                                    ?: 'Sem descrição'
                                }}
                            </p>

                        </div>

                        <button
                            type="button"
                            wire:click="removeCnae({{ $this->primaryCnae->id }})"
                            wire:confirm="Deseja realmente remover este CNAE da matriz?"
                            class="ec-danger-action"
                        >
                            Remover
                        </button>

                    </div>

                </div>

            @else

                <div class="ec-cnae-empty">

                    <div class="ec-empty-title">
                        Nenhum CNAE principal definido
                    </div>

                    <p class="ec-empty-description">
                        Adicione um CNAE ou torne um CNAE secundário o principal.
                    </p>

                </div>

            @endif


            {{-- SECUNDÁRIOS --}}
            @if ($this->secondaryCnaes->isNotEmpty())

                <div class="ec-cnae-block">

                    <div class="ec-cnae-block-label">
                        CNAEs secundários
                    </div>

                    <div class="ec-cnae-list">

                        @foreach ($this->secondaryCnaes as $cnae)

                            <div
                                wire:key="cnae-secondary-{{ $cnae->id }}"
                                class="ec-cnae-row"
                            >

                                <div>

                                    <div class="ec-cnae-row-code">
                                        {{ $cnae->code }}
                                    </div>

                                    <div class="ec-cnae-row-description">
                                        {{
                                            $cnae->description
                                            ?: 'Sem descrição'
                                        }}
                                    </div>

                                </div>


                                <div class="ec-cnae-actions">

                                    <button
                                        type="button"
                                        wire:click="makeCnaePrimary({{ $cnae->id }})"
                                        class="ec-link-action"
                                    >
                                        Tornar principal
                                    </button>

                                    <button
                                        type="button"
                                        wire:click="removeCnae({{ $cnae->id }})"
                                        wire:confirm="Deseja remover este CNAE da matriz?"
                                        class="ec-danger-action"
                                    >
                                        Remover
                                    </button>

                                </div>

                            </div>

                        @endforeach

                    </div>

                </div>

            @endif


            @if (
                ! $this->primaryCnae
                && $this->secondaryCnaes->isEmpty()
                && ! $showCnaeForm
            )

                <div class="mt-4 text-center">

                    <button
                        type="button"
                        wire:click="toggleCnaeForm"
                        class="ec-link-action"
                    >
                        + Cadastrar primeiro CNAE
                    </button>

                </div>

            @endif

        </div>

    </section>

</div>
