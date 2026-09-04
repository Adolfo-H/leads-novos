<?php

use App\Jobs\ResearchCompanyExports;
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

        'services.openai.api_key' => 'fake-key',
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
