<?php

use App\Jobs\ResearchCompanyExports;
use App\Models\Cnae;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('redirects guests away from company pages', function () {
    $this
        ->get(route('companies.index'))
        ->assertRedirect(route('login'));

    $this
        ->get(route('companies.create'))
        ->assertRedirect(route('login'));
});

it('renders the company index for an authenticated user', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->get(route('companies.index'))
        ->assertSuccessful()
        ->assertSee('Empresas')
        ->assertSee('Nenhuma empresa encontrada');
});

it('renders the company creation page', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this
        ->actingAs($user)
        ->get(route('companies.create'))
        ->assertSuccessful()
        ->assertSee('Nova empresa')
        ->assertSee('Razão social');
});

it('creates a company using the livewire page', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::companies.create')
        ->set('cnpj', '11.222.333/0001-81')
        ->set(
            'corporateName',
            'Cooperativa Agropecuária Teste Ltda'
        )
        ->set(
            'fantasyName',
            'Cooperativa Teste'
        )
        ->set('type', 'matrix')
        ->set('registrationStatus', 'ATIVA')
        ->set('state', 'PR')
        ->set('municipalityName', 'Toledo')
        ->set('email', 'contato@teste.com.br')
        ->set('shareCapital', '2.500.000,00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('companies.index'));

    expect(Company::count())->toBe(1);

    $company = Company::firstOrFail();

    expect($company->corporate_name)
        ->toBe(
            'Cooperativa Agropecuária Teste Ltda'
        );

    expect($company->share_capital)
        ->toBe('2500000.00');

    expect(
        $company
            ->establishments()
            ->firstOrFail()
            ->state
    )->toBe('PR');
});

it('rejects an invalid cnpj in the creation page', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::companies.create')
        ->set('cnpj', '00.000.000/0000-00')
        ->set(
            'corporateName',
            'Empresa Inválida'
        )
        ->call('save')
        ->assertHasErrors(['cnpj']);

    expect(Company::count())->toBe(0);
});

it('renders the company dossier', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $service = app(
        CompanyService::class
    );

    $company = $service
        ->createOrUpdateFromEstablishment(
            [
                'corporate_name' => 'Cooperativa Dossiê Teste',

                'share_capital' => 3500000,
            ],
            [
                'cnpj' => '11.222.333/0001-81',

                'type' => 'matrix',

                'registration_status' => 'ATIVA',

                'state' => 'PR',

                'municipality_name' => 'Palotina',
            ],
            [
                [
                    'code' => '4622200',

                    'description' => 'Comércio atacadista de soja',

                    'is_primary' => true,
                ],
            ]
        );

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Cooperativa Dossiê Teste'
        )
        ->assertSee(
            '11.222.333/0001-81'
        )
        ->assertSee(
            'Inteligência comercial'
        )
        ->assertSee(
            'Não verificado'
        )
        ->assertSee(
            '4622200'
        );
});

it('does not allow guests to open a company dossier', function () {
    $service = app(
        CompanyService::class
    );

    $company = $service
        ->createOrUpdateFromEstablishment(
            [
                'corporate_name' => 'Empresa Protegida',
            ],
            [
                'cnpj' => '11.222.333/0001-81',

                'type' => 'matrix',
            ]
        );

    $this
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertRedirect(
            route('login')
        );
});

it('renders the company edit page', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $service = app(
        CompanyService::class
    );

    $company = $service
        ->createOrUpdateFromEstablishment(
            [
                'corporate_name' => 'Empresa Editável',
            ],
            [
                'cnpj' => '11.222.333/0001-81',

                'type' => 'matrix',

                'state' => 'PR',
            ]
        );

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.edit',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Editar empresa'
        )
        ->assertSee(
            '11.222.333/0001-81'
        );
});

it('updates company and matrix data', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $service = app(
        CompanyService::class
    );

    $company = $service
        ->createOrUpdateFromEstablishment(
            [
                'corporate_name' => 'Empresa Antes',
            ],
            [
                'cnpj' => '11.222.333/0001-81',

                'type' => 'matrix',

                'state' => 'PR',

                'municipality_name' => 'Toledo',
            ]
        );

    Livewire::actingAs(
        $user
    )
        ->test(
            'pages::companies.edit',
            [
                'company' => $company,
            ]
        )
        ->set(
            'corporateName',
            'Empresa Depois'
        )
        ->set(
            'fantasyName',
            'Empresa Atualizada'
        )
        ->set(
            'shareCapital',
            '5.000.000,00'
        )
        ->set(
            'state',
            'SP'
        )
        ->set(
            'municipalityName',
            'Campinas'
        )
        ->set(
            'email',
            'FISCAL@EMPRESA.COM.BR'
        )
        ->set(
            'street',
            'Avenida Central'
        )
        ->set(
            'number',
            '1500'
        )
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(
            route(
                'companies.show',
                $company
            )
        );

    $company->refresh();

    expect(
        $company->corporate_name
    )->toBe(
        'Empresa Depois'
    );

    expect(
        $company->share_capital
    )->toBe(
        '5000000.00'
    );

    $matrix = $company
        ->establishments()
        ->where(
            'type',
            'matrix'
        )
        ->firstOrFail();

    expect(
        $matrix->fantasy_name
    )->toBe(
        'Empresa Atualizada'
    );

    expect(
        $matrix->state
    )->toBe('SP');

    expect(
        $matrix->municipality_name
    )->toBe(
        'Campinas'
    );

    expect(
        $matrix->email
    )->toBe(
        'fiscal@empresa.com.br'
    );

    expect(
        $matrix->street
    )->toBe(
        'Avenida Central'
    );
});

it('does not allow guests to edit companies', function () {
    $service = app(
        CompanyService::class
    );

    $company = $service
        ->createOrUpdateFromEstablishment(
            [
                'corporate_name' => 'Empresa Protegida',
            ],
            [
                'cnpj' => '11.222.333/0001-81',

                'type' => 'matrix',
            ]
        );

    $this
        ->get(
            route(
                'companies.edit',
                $company
            )
        )
        ->assertRedirect(
            route('login')
        );
});

it('renders export intelligence in the company dossier', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '55443322',

            'corporate_name' => 'Empresa Inteligência Exportação',
        ]);

    $company
        ->exportIntelligence()
        ->create([
            'direct_status' => 'yes',

            'direct_confidence' => 90,

            'direct_confirmed' => false,

            'indirect_status' => 'uncertain',

            'indirect_confidence' => 55,

            'indirect_confirmed' => false,

            'trading_status' => 'yes',

            'trading_confidence' => 78,

            'trading_confirmed' => false,

            'research_status' => 'completed',

            'research_provider' => 'fake-web',

            'researched_at' => now(),

            'research_completed_at' => now(),

            'metadata' => [],
        ]);

    $company
        ->exportEvidence()
        ->create([
            'fingerprint' => hash(
                'sha256',
                'company-pages-test'
            ),

            'dimension' => 'direct',

            'signal' => 'positive',

            'source_type' => 'government',

            'source_name' => 'Fonte pública teste',

            'source_url' => 'https://example.com/export',

            'title' => 'Registro exportador',

            'evidence_text' => 'A empresa aparece em uma '
                .'fonte pública de exportação.',

            'confidence' => 90,

            'is_confirmed' => false,

            'metadata' => [],
        ]);

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Pesquisa concluída'
        )
        ->assertSee(
            '90% de confiança'
        )
        ->assertSee(
            '55% de confiança'
        )
        ->assertSee(
            '78% de confiança'
        )
        ->assertSee(
            'Evidências de exportação'
        )
        ->assertSee(
            'Fonte pública teste'
        );
});

it('queues export research from the company dossier', function () {
    Queue::fake();

    config([
        'prospector.export_research.enabled' => true,

        'services.tavily.api_key' => 'fake-key',
    ]);

    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '66554433',

            'corporate_name' => 'Empresa Pesquisa Pela Tela',
        ]);

    $company
        ->icpScore()
        ->create([
            'score' => 90,

            'grade' => 'A',

            'label' => 'Excelente aderência',

            'version' => 'test',

            'factors' => [],

            'calculated_at' => now(),
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'not_found',

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    Livewire::actingAs($user)
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->call(
            'researchExports'
        )
        ->assertHasNoErrors();

    expect(
        $company
            ->exportIntelligence()
            ->firstOrFail()
            ->research_status
    )->toBe(
        'queued'
    );

    Queue::assertPushed(
        ResearchCompanyExports::class,
        1
    );
});

it('shows existing customers as blocked in the SDR score', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '07903169',

            'corporate_name' => 'Cliente SDR Teste',
        ]);

    $company
        ->icpScore()
        ->create([
            'score' => 100,

            'grade' => 'A',

            'label' => 'Alta aderência',

            'version' => 'test',

            'factors' => [],

            'calculated_at' => now(),
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'client',

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Não priorizar'
        )
        ->assertSee(
            'Empresa já é cliente'
        )
        ->assertSee(
            'Bloqueado'
        );
});

it('shows public email and phone from company branches', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '88776655',

            'corporate_name' => 'Empresa Contatos Filiais Teste',
        ]);

    $company
        ->establishments()
        ->create([
            'cnpj' => '88776655000100',

            'order_number' => '0001',

            'check_digits' => '00',

            'type' => 'matrix',

            'registration_status' => 'ATIVA',

            'state' => 'SP',

            'municipality_name' => 'São Paulo',
        ]);

    $company
        ->establishments()
        ->create([
            'cnpj' => '88776655000200',

            'order_number' => '0002',

            'check_digits' => '00',

            'type' => 'branch',

            'fantasy_name' => 'Filial Comercial',

            'registration_status' => 'ATIVA',

            'state' => 'GO',

            'municipality_name' => 'Rio Verde',

            'email' => 'fiscal.filial@empresa.com.br',

            'phone_1' => '64987654321',

            'phone_2' => '6433334444',
        ]);

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Contatos cadastrais do grupo'
        )
        ->assertSee(
            'fiscal.filial@empresa.com.br'
        )
        ->assertSee(
            '(64) 98765-4321'
        )
        ->assertSee(
            '(64) 3333-4444'
        )
        ->assertSee(
            'Rio Verde'
        );
});

it('consolidates duplicated contacts across establishments', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '99887766',

            'corporate_name' => 'Grupo Contatos Únicos Teste',
        ]);

    foreach (
        [
            [
                'cnpj' => '99887766000100',

                'order_number' => '0001',

                'check_digits' => '00',

                'type' => 'matrix',

                'municipality_name' => 'São Paulo',

                'state' => 'SP',

                'email' => 'fiscal@grupo.com.br',

                'phone_1' => '1133334444',
            ],
            [
                'cnpj' => '99887766000200',

                'order_number' => '0002',

                'check_digits' => '00',

                'type' => 'branch',

                'municipality_name' => 'Campinas',

                'state' => 'SP',

                'email' => 'FISCAL@GRUPO.COM.BR',

                'phone_1' => '(11) 3333-4444',
            ],
            [
                'cnpj' => '99887766000300',

                'order_number' => '0003',

                'check_digits' => '00',

                'type' => 'branch',

                'municipality_name' => 'Rio Verde',

                'state' => 'GO',

                'email' => 'comex@grupo.com.br',

                'phone_1' => '64999998888',
            ],
        ] as $establishment
    ) {
        $company
            ->establishments()
            ->create(
                array_merge(
                    [
                        'registration_status' => 'ATIVA',
                    ],
                    $establishment
                )
            );
    }

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Contatos únicos do grupo'
        )
        ->assertSee(
            '2 e-mail(s) único(s)'
        )
        ->assertSee(
            '2 telefone(s) único(s)'
        )
        ->assertSee(
            'fiscal@grupo.com.br'
        )
        ->assertSee(
            'comex@grupo.com.br'
        )
        ->assertSee(
            '2 unidade(s)'
        );
});

it('shows cnaes from matrix and branches in the group dossier', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '55667788',

            'corporate_name' => 'Grupo CNAE Filiais Teste',
        ]);

    $matrix =
        $company
            ->establishments()
            ->create([
                'cnpj' => '55667788000100',

                'order_number' => '0001',

                'check_digits' => '00',

                'type' => 'matrix',

                'registration_status' => 'ATIVA',

                'municipality_name' => 'São Paulo',

                'state' => 'SP',
            ]);

    $branch =
        $company
            ->establishments()
            ->create([
                'cnpj' => '55667788000200',

                'order_number' => '0002',

                'check_digits' => '00',

                'type' => 'branch',

                'registration_status' => 'ATIVA',

                'municipality_name' => 'Rio Verde',

                'state' => 'GO',
            ]);

    $sharedCnae =
        Cnae::query()
            ->create([
                'code' => '4622200',

                'description' => 'CNAE compartilhado grupo teste',
            ]);

    $branchOnlyCnae =
        Cnae::query()
            ->create([
                'code' => '0115600',

                'description' => 'CNAE exclusivo da filial teste',
            ]);

    $matrix
        ->cnaes()
        ->attach(
            $sharedCnae->id,
            [
                'is_primary' => true,
            ]
        );

    $branch
        ->cnaes()
        ->attach(
            $sharedCnae->id,
            [
                'is_primary' => false,
            ]
        );

    $branch
        ->cnaes()
        ->attach(
            $branchOnlyCnae->id,
            [
                'is_primary' => true,
            ]
        );

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'CNAEs do grupo'
        )
        ->assertSee(
            'CNAE compartilhado grupo teste'
        )
        ->assertSee(
            'CNAE exclusivo da filial teste'
        )
        ->assertSee(
            'Rio Verde'
        )
        ->assertSee(
            '2 unidade(s)'
        );
});

it('summarizes the active operational footprint of the company group', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '44556677',

            'corporate_name' => 'Grupo Presença Operacional Teste',
        ]);

    $establishments = [
        [
            'cnpj' => '44556677000100',

            'order_number' => '0001',

            'check_digits' => '00',

            'type' => 'matrix',

            'registration_status_code' => '02',

            'registration_status' => 'ATIVA',

            'state' => 'SP',

            'municipality_name' => 'São Paulo',
        ],
        [
            'cnpj' => '44556677000200',

            'order_number' => '0002',

            'check_digits' => '00',

            'type' => 'branch',

            'registration_status_code' => '02',

            'registration_status' => 'ATIVA',

            'state' => 'GO',

            'municipality_name' => 'Rio Verde',
        ],
        [
            'cnpj' => '44556677000300',

            'order_number' => '0003',

            'check_digits' => '00',

            'type' => 'branch',

            'registration_status_code' => '08',

            'registration_status' => 'BAIXADA',

            'state' => 'MT',

            'municipality_name' => 'Rondonópolis',
        ],
        [
            'cnpj' => '44556677000400',

            'order_number' => '0004',

            'check_digits' => '00',

            'type' => 'branch',

            'state' => 'PR',

            'municipality_name' => 'Cascavel',
        ],
    ];

    foreach (
        $establishments as $establishment
    ) {
        $company
            ->establishments()
            ->create(
                $establishment
            );
    }

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Presença operacional do grupo'
        )
        ->assertSee(
            'data-operational-total="4"',
            false
        )
        ->assertSee(
            'data-operational-active="2"',
            false
        )
        ->assertSee(
            'data-operational-states="2"',
            false
        )
        ->assertSee(
            'data-operational-cities="2"',
            false
        )
        ->assertSee(
            'BAIXADA'
        )
        ->assertSee(
            'SEM STATUS'
        )
        ->assertSee(
            'SP'
        )
        ->assertSee(
            'GO'
        );
});

it('shows the full public address of company branches', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '22334455',

            'corporate_name' => 'Grupo Endereço Filial Teste',
        ]);

    $company
        ->establishments()
        ->create([
            'cnpj' => '22334455000100',

            'order_number' => '0001',

            'check_digits' => '00',

            'type' => 'matrix',

            'registration_status' => 'ATIVA',

            'state' => 'SP',

            'municipality_name' => 'São Paulo',
        ]);

    $company
        ->establishments()
        ->create([
            'cnpj' => '22334455000200',

            'order_number' => '0002',

            'check_digits' => '00',

            'type' => 'branch',

            'registration_status' => 'ATIVA',

            'address_type' => 'RODOVIA',

            'street' => 'BR 060',

            'number' => 'KM 12',

            'complement' => 'ARMAZEM 2',

            'neighborhood' => 'ZONA RURAL',

            'zip_code' => '75900000',

            'state' => 'GO',

            'municipality_name' => 'Rio Verde',
        ]);

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'RODOVIA BR 060'
        )
        ->assertSee(
            'KM 12'
        )
        ->assertSee(
            'ARMAZEM 2'
        )
        ->assertSee(
            'ZONA RURAL'
        )
        ->assertSee(
            'Rio Verde/GO'
        )
        ->assertSee(
            'CEP 75900-000'
        );
});

it('shows registration dates and special situation for branches', function () {
    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '66778899',

            'corporate_name' => 'Grupo Situação Especial Teste',
        ]);

    $company
        ->establishments()
        ->create([
            'cnpj' => '66778899000100',

            'order_number' => '0001',

            'check_digits' => '00',

            'type' => 'matrix',

            'registration_status_code' => '02',

            'registration_status' => 'ATIVA',

            'start_date' => '2010-01-10',

            'state' => 'SP',

            'municipality_name' => 'São Paulo',
        ]);

    $company
        ->establishments()
        ->create([
            'cnpj' => '66778899000200',

            'order_number' => '0002',

            'check_digits' => '00',

            'type' => 'branch',

            'registration_status_code' => '03',

            'registration_status' => 'SUSPENSA',

            'registration_status_date' => '2024-01-15',

            'registration_status_reason_code' => '21',

            'start_date' => '2018-05-10',

            'special_situation' => 'REGIME ESPECIAL TESTE',

            'special_situation_date' => '2024-02-20',

            'source_updated_at' => '2026-09-04 12:00:00',

            'state' => 'GO',

            'municipality_name' => 'Rio Verde',
        ]);

    $this
        ->actingAs($user)
        ->get(
            route(
                'companies.show',
                $company
            )
        )
        ->assertSuccessful()
        ->assertSee(
            'Cadastro'
        )
        ->assertSee(
            '10/05/2018'
        )
        ->assertSee(
            '15/01/2024'
        )
        ->assertSee(
            'Motivo:'
        )
        ->assertSee(
            '21'
        )
        ->assertSee(
            'Situação especial'
        )
        ->assertSee(
            'REGIME ESPECIAL TESTE'
        )
        ->assertSee(
            '20/02/2024'
        )
        ->assertSee(
            'Fonte atualizada em'
        )
        ->assertSee(
            '04/09/2026'
        );
});

it('allows manual export research even when automatic research is blocked', function () {
    Queue::fake();

    config([
        'prospector.export_research.enabled' => true,

        'services.tavily.api_key' => 'fake-tavily-key',
    ]);

    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    $company =
        Company::query()->create([
            'cnpj_root' => '99887766',

            'corporate_name' => 'EMPRESA PESQUISA MANUAL TESTE',
        ]);

    $company
        ->icpScore()
        ->create([
            'score' => 90,

            'grade' => 'A',

            'label' => 'ICP A',

            'version' => 'test',

            'factors' => [],

            'calculated_at' => now(),
        ]);

    /*
     * Cliente seria bloqueado pela
     * pesquisa automática.
     */
    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'client',

            'contacted_count' => 0,

            'associated_deals_count' => 1,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    Livewire::actingAs(
        $user
    )
        ->test(
            'pages::companies.show',
            [
                'company' => $company,
            ]
        )
        ->assertSee(
            'Tavily'
        )
        ->assertSee(
            'Pesquisar mesmo assim'
        )
        ->call(
            'researchExportsForce'
        )
        ->assertHasNoErrors();

    Queue::assertPushed(
        ResearchCompanyExports::class,
        fn (
            ResearchCompanyExports $job
        ): bool => $job->companyId
                === $company->id
            && $job->force
                === true
    );
});
