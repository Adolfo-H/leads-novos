<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\HubSpotActivity;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\LeadCommercialSituationV52Service;
use App\Services\LeadGlobalStageExcelV45Service;
use App\Services\LeadUnifiedExcelV23Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

uses(RefreshDatabase::class);

function v52LeadCompany(string $root, string $name, string $status, ?\DateTimeInterface $activity = null): Company
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => $name,
        'source' => 'test',
    ]);
    $company->sdrScore()->create([
        'score' => 77,
        'priority' => 'high',
        'label' => 'Prioridade alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'v52',
        'metadata' => [],
        'calculated_at' => now(),
    ]);
    CompanyHubSpotLead::query()->create([
        'company_id' => $company->id,
        'hubspot_company_id' => 'v52-hs-'.$root,
        'hubspot_deal_id' => 'v52-deal-'.$root,
        'work_status' => $status,
        'last_activity_at' => $activity,
        'open_task_count' => 0,
        'metadata' => [],
    ]);

    return $company;
}

function v52Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('shows a future lead as Conhecido after activity in 30 days and matches the CRM filter', function (): void {
    $recent = v52LeadCompany('97520001', 'V52 Empresa com Contato Recente', 'future', now()->subDays(12));
    v52LeadCompany('97520002', 'V52 Empresa com Contato Antigo', 'future', now()->subDays(110));

    $map = app(LeadCommercialSituationV52Service::class)->forCompanies(collect([$recent]));
    expect($map[$recent->id] ?? null)->toBe('known');

    Livewire::actingAs(v52Manager())->test('pages::leads.index')
        ->assertSee('V52 Empresa com Contato Recente')
        ->assertSeeHtml('lv13-status--known')
        ->set('crmSituation', 'known')
        ->assertSee('V52 Empresa com Contato Recente')
        ->assertDontSee('V52 Empresa com Contato Antigo');
});

it('shows Reprospecção only with dated inactivity of at least 90 days', function (): void {
    $old = v52LeadCompany('97520003', 'V52 Inativa Confirmada', 'future', now()->subDays(102));
    $mid = v52LeadCompany('97520004', 'V52 Atividade Intermediaria', 'future', now()->subDays(50));
    $unknown = v52LeadCompany('97520005', 'V52 Sem Data Comprovada', 'future');

    $map = app(LeadCommercialSituationV52Service::class)->forCompanies(collect([$old, $mid, $unknown]));
    expect($map[$old->id] ?? null)->toBe('reprospecting')
        ->and($map)->not->toHaveKey($mid->id)
        ->and($map)->not->toHaveKey($unknown->id);

    Livewire::actingAs(v52Manager())->test('pages::leads.index')
        ->set('crmSituation', 'reprospecting')
        ->assertSee('V52 Inativa Confirmada')
        ->assertDontSee('V52 Atividade Intermediaria')
        ->assertDontSee('V52 Sem Data Comprovada');
});

it('recognizes a recent HubSpot note even if the legacy snapshot is old', function (): void {
    $company = v52LeadCompany('97520006', 'V52 Empresa Nota Recente', 'future', now()->subDays(140));
    $hub = HubSpotCompany::query()->create([
        'hubspot_id' => 'v52-company-note',
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
    ]);
    $note = HubSpotActivity::query()->create([
        'hubspot_id' => 'v52-note',
        'object_type' => 'note',
        'title' => 'Conversa registrada no HubSpot',
        'occurred_at' => now()->subDays(8),
        'is_deleted' => false,
    ]);
    $hub->activities()->attach($note->id);

    $map = app(LeadCommercialSituationV52Service::class)->forCompanies(collect([$company]));
    expect($map[$company->id] ?? null)->toBe('known');
    Livewire::actingAs(v52Manager())->test('pages::leads.index')
        ->set('crmSituation', 'known')->assertSee('V52 Empresa Nota Recente');
});

it('classifies converted as Cliente even without a won deal or legacy CRM client', function (): void {
    $converted = v52LeadCompany('97520007', 'V52 Convertida Confirmada', 'converted');
    $recent = v52LeadCompany('97520008', 'V52 Outra Empresa Recente', 'future', now()->subDays(5));
    $map = app(LeadCommercialSituationV52Service::class)->forCompanies(collect([$converted, $recent]));
    expect($map[$converted->id] ?? null)->toBe('client');

    Livewire::actingAs(v52Manager())->test('pages::leads.index')
        ->set('crmSituation', 'client')
        ->assertSee('V52 Convertida Confirmada')
        ->assertDontSee('V52 Outra Empresa Recente')
        ->assertSeeHtml('lv13-status--client');
});

it('keeps the raw HubSpot work status unchanged and preserves other labels', function (): void {
    $future = v52LeadCompany('97520009', 'V52 Transicao 60 Dias', 'future', now()->subDays(60));
    $contacting = v52LeadCompany('97520010', 'V52 Em Contato Normal', 'contacting', now()->subDays(2));
    $map = app(LeadCommercialSituationV52Service::class)->forCompanies(collect([$future, $contacting]));

    expect($map)->not->toHaveKey($future->id)
        ->and($map)->not->toHaveKey($contacting->id)
        ->and($future->fresh()->hubSpotLead->work_status)->toBe('future')
        ->and($contacting->fresh()->hubSpotLead->work_status)->toBe('contacting');
});

/** A planilha é criada antes de fazer streaming; inspecionar células sem baixar arquivos. */
function v52Workbook(StreamedResponse $response): Spreadsheet
{
    $pending = [$response->getCallback()];
    while ($pending !== []) {
        $callback = array_pop($pending);
        if (! $callback instanceof \Closure) {
            continue;
        }
        foreach ((new \ReflectionFunction($callback))->getStaticVariables() as $value) {
            if ($value instanceof Spreadsheet) {
                return $value;
            }
            if ($value instanceof \Closure) {
                $pending[] = $value;
            }
        }
    }

    throw new \RuntimeException('Planilha não localizada no stream do Excel.');
}

it('exports Conhecido e Cliente na coluna I sem alterar a estrutura V51 de 21 colunas', function (): void {
    $known = v52LeadCompany('97520011', 'V52 Excel Conhecido', 'future', now()->subDays(6));
    $client = v52LeadCompany('97520012', 'V52 Excel Cliente', 'converted');

    $workbook = v52Workbook(app(LeadUnifiedExcelV23Service::class)->excel(collect([$known, $client]), collect()));
    try {
        $sheet = $workbook->getActiveSheet();
        expect($sheet->getHighestColumn())->toBe('U')
            ->and($sheet->getCell('I1')->getValue())->toBe('Status comercial')
            ->and($sheet->getCell('I2')->getValue())->toBe('Conhecido')
            ->and($sheet->getCell('I3')->getValue())->toBe('Cliente')
            ->and($sheet->getCell('L1')->getValue())->toBe('Nome do contato')
            ->and($sheet->getCell('M1')->getValue())->toBe('Telefones')
            ->and($sheet->getCell('N1')->getValue())->toBe('E-mails');
    } finally {
        $workbook->disconnectWorksheets();
    }
});

it('exports a converted fiscal company as Cliente in the HubSpot stage Excel', function (): void {
    $fiscal = v52LeadCompany('97520013', 'V52 Cliente da Etapa', 'converted');
    $hub = HubSpotCompany::query()->create([
        'hubspot_id' => 'v52-hub-stage',
        'company_id' => $fiscal->id,
        'name' => 'V52 Cliente da Etapa',
        'match_source' => 'manual_verified',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v52-deal-stage',
        'name' => 'V52 Negócio de Etapa',
        'stage_label' => 'Reunião Realizada',
        'is_closed_won' => false,
    ]);
    $deal->companies()->attach($hub->id, ['is_primary' => true]);

    $book = v52Workbook(app(LeadGlobalStageExcelV45Service::class)->excel(collect([$deal]), 'Reunião Realizada'));
    try {
        $sheet = $book->getActiveSheet();
        expect($sheet->getHighestColumn())->toBe('U')
            ->and($sheet->getCell('I1')->getValue())->toBe('Status comercial')
            ->and($sheet->getCell('I2')->getValue())->toBe('Cliente');
    } finally {
        $book->disconnectWorksheets();
    }
});
