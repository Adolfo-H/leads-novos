<?php

use App\Contracts\CnpjDataProvider;
use App\Contracts\CnpjGroupDataProvider;
use App\Contracts\CrmCompanyProvider;
use App\Exceptions\CnpjNotFoundException;
use App\Jobs\EnrichImportItem;
use App\Models\Company;
use App\Services\CnpjImportService;
use App\Services\ImportQueueService;
use App\Support\Cnpj;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

it('queues ready import items', function () {
    Queue::fake();

    $base =
        '223334440001';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    $batch = app(
        CnpjImportService::class
    )->import([
        $cnpj,
    ]);

    $total = app(
        ImportQueueService::class
    )->dispatchReady(
        $batch
    );

    expect($total)
        ->toBe(1);

    expect(
        $batch
            ->items()
            ->firstOrFail()
            ->status
    )->toBe('queued');

    Queue::assertPushed(
        EnrichImportItem::class,
        1
    );
});

it('enriches a queued cnpj into a company', function () {
    $provider =
        new class implements CnpjDataProvider
        {
            public function name(): string
            {
                return 'fake';
            }

            public function lookup(
                string $cnpj
            ): array {
                return [
                    'razao_social' => 'AGROPECUARIA AUTOMATICA LTDA',

                    'nome_fantasia' => 'AGRO AUTOMATICA',

                    'situacao_cadastral' => 'ATIVA',

                    'data_abertura' => '2020-01-15',

                    'capital_social' => 5000000,

                    'porte' => 'DEMAIS',

                    'uf' => 'MT',

                    'municipio' => 'SORRISO',

                    'logradouro' => 'AVENIDA DAS SOJAS',

                    'numero' => '1000',

                    'email' => 'FISCAL@AGRO.COM.BR',

                    'telefone' => '6633334444',

                    'cnae_principal' => [
                        'codigo' => '4622200',

                        'descricao' => 'Comércio atacadista de soja',
                    ],

                    'cnaes_secundarios' => [
                        [
                            'codigo' => '0115600',

                            'descricao' => 'Cultivo de soja',
                        ],
                    ],
                ];
            }
        };

    app()->instance(
        CnpjDataProvider::class,
        $provider
    );

    $groupProvider =
        new class implements CnpjGroupDataProvider
        {
            public function name(): string
            {
                return 'fake-receita';
            }

            public function lookupRoot(
                string $cnpjRoot
            ): array {
                throw new CnpjNotFoundException(
                    'Não encontrado na fixture.'
                );
            }
        };

    app()->instance(
        CnpjGroupDataProvider::class,
        $groupProvider
    );

    $base =
        '223334440001';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    $batch = app(
        CnpjImportService::class
    )->import([
        $cnpj,
    ]);

    $item =
        $batch
            ->items()
            ->firstOrFail();

    $item->update([
        'status' => 'queued',
    ]);

    EnrichImportItem::dispatchSync(
        $item->id
    );

    $item->refresh();

    expect(
        $item->status
    )->toBe('completed');

    expect(
        $item->company_id
    )->not->toBeNull();

    $company =
        $item->company()
            ->firstOrFail();

    expect(
        $company->corporate_name
    )->toBe(
        'AGROPECUARIA AUTOMATICA LTDA'
    );

    expect(
        $company->share_capital
    )->toBe(
        '5000000.00'
    );

    $matrix =
        $company
            ->establishments()
            ->firstOrFail();

    expect(
        $matrix->state
    )->toBe('MT');

    expect(
        $matrix->email
    )->toBe(
        'fiscal@agro.com.br'
    );

    expect(
        $matrix
            ->cnaes()
            ->count()
    )->toBe(2);
});

it('enriches an imported cnpj with its complete company group', function () {
    $matrixBase =
        '334445550001';

    $branchBase =
        '334445550002';

    $matrixCnpj =
        $matrixBase
        .Cnpj::calculateCheckDigits(
            $matrixBase
        );

    $branchCnpj =
        $branchBase
        .Cnpj::calculateCheckDigits(
            $branchBase
        );

    $groupProvider =
        new class($matrixCnpj, $branchCnpj) implements CnpjGroupDataProvider
        {
            public function __construct(
                private readonly string $matrix,
                private readonly string $branch,
            ) {}

            public function name(): string
            {
                return 'receita-local-fake';
            }

            public function lookupRoot(
                string $cnpjRoot
            ): array {
                return [
                    'company' => [
                        'corporate_name' => 'COOPERATIVA GRUPO AUTOMATICO',

                        'legal_nature_code' => '2143',

                        'legal_nature_description' => 'Cooperativa',

                        'share_capital' => 5000000,

                        'size_code' => '05',

                        'size_description' => 'Demais',

                        'source' => $this->name(),
                    ],

                    'establishments' => [
                        [
                            'establishment' => [
                                'cnpj' => $this->matrix,

                                'type' => 'matrix',

                                'registration_status_code' => '02',

                                'registration_status' => 'ATIVA',

                                'state' => 'MT',

                                'municipality_name' => 'SORRISO',

                                'source' => $this->name(),
                            ],

                            'cnaes' => [
                                [
                                    'code' => '4622200',

                                    'description' => 'Comércio atacadista de soja',

                                    'is_primary' => true,
                                ],
                            ],
                        ],

                        [
                            'establishment' => [
                                'cnpj' => $this->branch,

                                'type' => 'branch',

                                'registration_status_code' => '02',

                                'registration_status' => 'ATIVA',

                                'state' => 'GO',

                                'municipality_name' => 'RIO VERDE',

                                'source' => $this->name(),
                            ],

                            'cnaes' => [],
                        ],
                    ],
                ];
            }
        };

    app()->instance(
        CnpjGroupDataProvider::class,
        $groupProvider
    );

    $batch = app(
        CnpjImportService::class
    )->import([
        $matrixCnpj,
    ]);

    $item =
        $batch
            ->items()
            ->firstOrFail();

    $item->update([
        'status' => 'queued',
    ]);

    EnrichImportItem::dispatchSync(
        $item->id
    );

    $item->refresh();

    expect(
        $item->status
    )->toBe(
        'completed'
    );

    expect(
        data_get(
            $item->metadata,
            'provider'
        )
    )->toBe(
        'receita-local-fake'
    );

    expect(
        data_get(
            $item->metadata,
            'group_enrichment'
        )
    )->toBeTrue();

    expect(
        data_get(
            $item->metadata,
            'establishments'
        )
    )->toBe(2);

    $company =
        $item->company()
            ->firstOrFail();

    expect(
        $company
            ->establishments()
            ->count()
    )->toBe(2);
});

it('checks CRM automatically after group enrichment', function () {
    $base =
        '445556660001';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    $groupProvider =
        new class($cnpj) implements CnpjGroupDataProvider
        {
            public function __construct(
                private readonly string $cnpj
            ) {}

            public function name(): string
            {
                return 'receita-local-fake';
            }

            public function lookupRoot(
                string $cnpjRoot
            ): array {
                return [
                    'company' => [
                        'corporate_name' => 'COOPERATIVA CRM AUTOMATICA',

                        'legal_nature_code' => '2143',

                        'legal_nature_description' => 'Cooperativa',

                        'share_capital' => 5000000,

                        'size_code' => '05',

                        'size_description' => 'Demais',
                    ],

                    'establishments' => [
                        [
                            'establishment' => [
                                'cnpj' => $this->cnpj,

                                'type' => 'matrix',

                                'registration_status_code' => '02',

                                'registration_status' => 'ATIVA',

                                'state' => 'MT',

                                'email' => 'fiscal@crm-automatica.com.br',
                            ],

                            'cnaes' => [
                                [
                                    'code' => '4622200',

                                    'description' => 'Comércio atacadista de soja',

                                    'is_primary' => true,
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

    app()->instance(
        CnpjGroupDataProvider::class,
        $groupProvider
    );

    app()->instance(
        CrmCompanyProvider::class,
        new class implements CrmCompanyProvider
        {
            public function name(): string
            {
                return 'hubspot-fake';
            }

            public function findCompany(
                Company $company
            ): array {
                return [
                    'found' => true,

                    'external_id' => '987654',

                    'name' => 'Cooperativa CRM Automatica',

                    'domain' => 'crm-automatica.com.br',

                    'lifecycle_stage' => 'customer',

                    'owner_id' => '123',

                    'contacted_count' => 12,

                    'associated_deals_count' => 3,

                    'deals' => [
                        [
                            'id' => 'deal-won-987654',

                            'name' => 'Cooperativa CRM Automatica - Fechado',

                            'stage_id' => 'closedwon',

                            'stage_label' => 'Negócio fechado',

                            'pipeline_id' => 'default',

                            'is_closed' => true,

                            'is_closed_won' => true,

                            'closed_at' => '2026-08-20T10:00:00Z',
                        ],
                    ],

                    'last_contacted_at' => '2026-09-01T10:00:00Z',

                    'matched_by' => 'domain',

                    'matched_value' => 'crm-automatica.com.br',

                    'external_url' => 'https://example.test/987654',

                    'metadata' => [],
                ];
            }
        }
    );

    $batch = app(
        CnpjImportService::class
    )->import([
        $cnpj,
    ]);

    $item =
        $batch
            ->items()
            ->firstOrFail();

    $item->update([
        'status' => 'queued',
    ]);

    EnrichImportItem::dispatchSync(
        $item->id
    );

    $item->refresh();

    expect(
        $item->status
    )->toBe('completed');

    expect(
        data_get(
            $item->metadata,
            'crm.checked'
        )
    )->toBeTrue();

    expect(
        data_get(
            $item->metadata,
            'crm.status'
        )
    )->toBe('client');

    $company =
        $item
            ->company()
            ->firstOrFail();

    $crm =
        $company
            ->crmCheck()
            ->firstOrFail();

    expect(
        $crm->status
    )->toBe('client');

    expect(
        $crm->external_id
    )->toBe('987654');

    expect(
        $crm->contacted_count
    )->toBe(12);
});

it('automatically requeues a stale queued import item', function () {
    Queue::fake();

    $base =
        '887755440001';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    $batch = app(
        CnpjImportService::class
    )->import([
        $cnpj,
    ]);

    $item =
        $batch
            ->items()
            ->firstOrFail();

    /*
     * Simula exatamente um registro
     * que consta como "queued" no banco,
     * mas ficou sem progresso.
     */
    $item->update([
        'status' => 'queued',
    ]);

    DB::table(
        'import_items'
    )
        ->where(
            'id',
            $item->id
        )
        ->update([
            'updated_at' => now()
                ->subMinutes(30),
        ]);

    $recovered = app(
        ImportQueueService::class
    )->recoverStale(
        $batch,
        afterMinutes: 20,
    );

    expect(
        $recovered
    )->toBe(1);

    $item->refresh();

    expect(
        $item->status
    )->toBe('queued');

    expect(
        data_get(
            $item->metadata,
            'queue.recovery_count'
        )
    )->toBe(1);

    expect(
        data_get(
            $item->metadata,
            'queue.previous_status'
        )
    )->toBe('queued');

    Queue::assertPushed(
        EnrichImportItem::class,
        fn (
            EnrichImportItem $job
        ): bool => $job->importItemId
                === $item->id
    );
});

it('does not requeue an import item that is still recent', function () {
    Queue::fake();

    $base =
        '887755440002';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    $batch = app(
        CnpjImportService::class
    )->import([
        $cnpj,
    ]);

    $item =
        $batch
            ->items()
            ->firstOrFail();

    $item->update([
        'status' => 'queued',
    ]);

    $recovered = app(
        ImportQueueService::class
    )->recoverStale(
        $batch,
        afterMinutes: 20,
    );

    expect(
        $recovered
    )->toBe(0);

    Queue::assertNothingPushed();
});

it('prevents simultaneous processing of the same import item', function () {
    $job =
        new EnrichImportItem(
            987
        );

    $middleware =
        $job->middleware();

    expect(
        $middleware
    )->toHaveCount(1);

    expect(
        $middleware[0]
    )->toBeInstanceOf(
        WithoutOverlapping::class
    );
});

it('recovers stale imports through the scheduled command', function () {
    Queue::fake();

    $base =
        '776655440001';

    $cnpj =
        $base
        .Cnpj::calculateCheckDigits(
            $base
        );

    $batch = app(
        CnpjImportService::class
    )->import([
        $cnpj,
    ]);

    $batch->update([
        'status' => 'processing',
    ]);

    $item =
        $batch
            ->items()
            ->firstOrFail();

    $item->update([
        'status' => 'queued',
    ]);

    DB::table(
        'import_items'
    )
        ->where(
            'id',
            $item->id
        )
        ->update([
            'updated_at' => now()
                ->subMinutes(30),
        ]);

    $this
        ->artisan(
            'imports:recover-stale',
            [
                '--minutes' => 20,
            ]
        )
        ->assertSuccessful();

    $item->refresh();

    expect(
        data_get(
            $item->metadata,
            'queue.recovery_count'
        )
    )->toBe(1);

    Queue::assertPushed(
        EnrichImportItem::class,
        fn (
            EnrichImportItem $job
        ): bool => $job->importItemId
                === $item->id
    );
});
