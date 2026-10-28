<?php

use App\Models\Company;
use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\User;
use App\Services\LeadExportService;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

function v45Company(string $cnpjRoot, string $name, string $priority = 'high'): Company
{
    $company = Company::query()->create([
        'cnpj_root' => $cnpjRoot,
        'corporate_name' => $name,
    ]);
    $company->sdrScore()->create([
        'score' => 75,
        'priority' => $priority,
        'label' => 'Teste',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'v45',
        'metadata' => [],
        'calculated_at' => now(),
    ]);

    return $company;
}

function v45Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function v45XlsxSheet(StreamedResponse $response): Spreadsheet
{
    ob_start();
    try {
        $response->sendContent();
        $bytes = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    expect(substr($bytes, 0, 2))->toBe('PK');
    $temp = tempnam(sys_get_temp_dir(), 'v45-xlsx-');
    if ($temp === false) {
        throw new RuntimeException('Não foi possível alocar XLSX temporário de teste.');
    }
    try {
        file_put_contents($temp, $bytes);

        return (new Xlsx)->load($temp);
    } finally {
        @unlink($temp);
    }
}

it('exports only three selected companies instead of the entire filtered queue', function (): void {
    $manager = v45Manager();
    $first = v45Company('94500001', 'V45 EMPRESA ALFA');
    $second = v45Company('94500002', 'V45 EMPRESA BETA');
    $third = v45Company('94500003', 'V45 EMPRESA GAMA');
    v45Company('94500004', 'V45 EMPRESA NAO SELECIONADA');

    $component = Livewire::actingAs($manager)->test('pages::leads.index')
        ->set('selectedLeadIds', [(string) $first->id, (string) $second->id, (string) $third->id]);
    $book = v45XlsxSheet($component->instance()->exportExcel(app(LeadExportService::class)));
    try {
        $sheet = $book->getActiveSheet();
        expect($sheet->getHighestDataRow())->toBe(4)
            ->and($sheet->getCell('A2')->getValue())->toBe('V45 EMPRESA ALFA')
            ->and($sheet->getCell('A3')->getValue())->toBe('V45 EMPRESA BETA')
            ->and($sheet->getCell('A4')->getValue())->toBe('V45 EMPRESA GAMA');
    } finally {
        $book->disconnectWorksheets();
    }
});

it('intersects selected IDs with the active filter and does not export unselected CRM records', function (): void {
    $manager = v45Manager();
    $high = v45Company('94500005', 'V45 EMPRESA DE PRIORIDADE ALTA', 'high');
    $medium = v45Company('94500006', 'V45 EMPRESA DE PRIORIDADE MEDIA', 'medium');

    $component = Livewire::actingAs($manager)->test('pages::leads.index')
        ->set('priority', 'high')
        ->set('selectedLeadIds', [(string) $high->id, (string) $medium->id]);

    $book = v45XlsxSheet($component->instance()->exportExcel(app(LeadExportService::class)));
    try {
        $sheet = $book->getActiveSheet();
        expect($sheet->getHighestDataRow())->toBe(2)
            ->and($sheet->getCell('A2')->getValue())->toBe('V45 EMPRESA DE PRIORIDADE ALTA');
    } finally {
        $book->disconnectWorksheets();
    }
});

it('prevents a seller from exporting company IDs outside their assigned portfolio', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $mine = v45Company('94500007', 'V45 EMPRESA AUTORIZADA');
    $someoneElse = v45Company('94500008', 'V45 EMPRESA DE OUTRO USUARIO');
    $mine->leadWorkState()->create(['assigned_user_id' => $seller->id, 'status' => 'new']);

    $component = Livewire::actingAs($seller)->test('pages::leads.index')
        ->set('selectedLeadIds', [(string) $mine->id, (string) $someoneElse->id]);
    $book = v45XlsxSheet($component->instance()->exportExcel(app(LeadExportService::class)));
    try {
        $sheet = $book->getActiveSheet();
        expect($sheet->getHighestDataRow())->toBe(2)
            ->and($sheet->getCell('A2')->getValue())->toBe('V45 EMPRESA AUTORIZADA');
    } finally {
        $book->disconnectWorksheets();
    }
});

it('shows filtered HubSpot deals in Empresas para trabalhar, without creating a second panel', function (): void {
    $manager = v45Manager();
    HubSpotPipelineStage::query()->create([
        'pipeline_id' => 'default',
        'pipeline_label' => 'Pipeline de vendas',
        'stage_id' => '94500010',
        'stage_label' => 'Oportunidade Futura',
        'display_order' => 1,
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => '945000111',
        'name' => 'V45 NEGOCIO SEM CNPJ',
        'pipeline_id' => 'default',
        'stage_id' => '94500010',
        'stage_label' => 'Rótulo antigo',
        'is_closed' => false,
    ]);

    $page = Livewire::actingAs($manager)->test('pages::leads.index');
    $index = collect($page->get('globalHubSpotStageOptions'))
        ->pluck('label')->search('Oportunidade Futura');
    expect($index)->not->toBeFalse();
    $page->call('applyGlobalHubSpotStageView', (int) $index)
        ->assertSee('Empresas para trabalhar')
        ->assertSee('V45 NEGOCIO SEM CNPJ')
        ->assertSee('lf45-stage-row', false)
        ->assertDontSee('lf41-global-results', false)
        ->assertSee('Negócios do HubSpot · Oportunidade Futura');
});

it('exports only selected stage deals, one row per deal, including orphan deals', function (): void {
    $manager = v45Manager();
    HubSpotPipelineStage::query()->create([
        'pipeline_id' => 'default',
        'pipeline_label' => 'Pipeline de vendas',
        'stage_id' => '94500011',
        'stage_label' => 'Reunião Agendada',
        'display_order' => 3,
    ]);
    $a = HubSpotDeal::query()->create([
        'hubspot_id' => '945000211',
        'name' => 'V45 REUNIAO A',
        'pipeline_id' => 'default',
        'stage_id' => '94500011',
        'stage_label' => 'Reunião Agendada',
    ]);
    $b = HubSpotDeal::query()->create([
        'hubspot_id' => '945000212',
        'name' => 'V45 REUNIAO B',
        'pipeline_id' => 'default',
        'stage_id' => '94500011',
        'stage_label' => 'Reunião Agendada',
    ]);
    $unselected = HubSpotDeal::query()->create([
        'hubspot_id' => '945000213',
        'name' => 'V45 REUNIAO NAO SELECIONADA',
        'pipeline_id' => 'default',
        'stage_id' => '94500011',
        'stage_label' => 'Reunião Agendada',
    ]);

    $component = Livewire::actingAs($manager)->test('pages::leads.index');
    $index = collect($component->get('globalHubSpotStageOptions'))
        ->pluck('label')->search('Reunião Agendada');
    expect($index)->not->toBeFalse();
    $component->call('applyGlobalHubSpotStageView', (int) $index)
        ->set('selectedGlobalDealIds', [(string) $a->id, (string) $b->id]);
    $book = v45XlsxSheet($component->instance()->exportExcel(app(LeadExportService::class)));
    try {
        $sheet = $book->getActiveSheet();
        expect($sheet->getHighestDataRow())->toBe(3)
            ->and($sheet->getCell('R2')->getValue())->toBe('945000211')
            ->and($sheet->getCell('R3')->getValue())->toBe('945000212')
            ->and($sheet->getCell('S2')->getValue())->toBe('Reunião Agendada');
    } finally {
        $book->disconnectWorksheets();
    }
});

it('keeps header export and Alpine selection synchronized with Livewire', function (): void {
    $leads = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $global = file_get_contents(resource_path('views/partials/leads-figma-stages-v36.blade.php'));
    $inline = file_get_contents(resource_path('views/partials/leads-global-stage-list-v45.blade.php'));
    expect($leads)
        ->toContain("entangle('selectedLeadIds').live")
        ->toContain("entangle('selectedGlobalDealIds').live")
        ->toContain('wire:click="exportExcel"')
        ->toContain('selectedExportIdsV45')
        ->toContain("@include('partials.leads-global-stage-list-v45')")
        ->and($inline)
        ->toContain('stageSelectedIds')
        ->toContain('lf43-stage-identity-actions')
        ->and($global)->not->toContain('lf41-global-results');
});
