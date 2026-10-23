<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\User;
use App\Services\LeadExportService;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

function v48User(string $name, string $role): User
{
    $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => $role])->save();

    return $user;
}

function v48Stage(string $label = 'Reunião Realizada'): void
{
    HubSpotPipelineStage::query()->firstOrCreate([
        'pipeline_id' => 'default',
        'stage_id' => '48480048',
    ], [
        'pipeline_label' => 'Pipeline de vendas',
        'stage_label' => $label,
        'display_order' => 4,
    ]);
}

function v48Deal(User $owner, string $root, string $name, string $dealId): HubSpotDeal
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => $name,
    ]);
    $company->leadWorkState()->create([
        'assigned_user_id' => $owner->id,
        'status' => 'new',
    ]);
    $company->sdrScore()->create([
        'score' => 76,
        'priority' => 'high',
        'label' => 'Alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'v48',
        'metadata' => [],
        'calculated_at' => now(),
    ]);
    $remote = HubSpotCompany::query()->create([
        'hubspot_id' => 'v48-remote-'.$dealId,
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
        'name' => $name,
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => $dealId,
        'name' => 'Negócio '.$name,
        'pipeline_id' => 'default',
        'stage_id' => '48480048',
        'stage_label' => 'Reunião Realizada',
        'is_closed' => false,
    ]);
    $remote->deals()->attach($deal->id, ['is_primary' => true]);

    return $deal;
}

function v48Workbook(StreamedResponse $response): Spreadsheet
{
    ob_start();
    try {
        $response->sendContent();
        $content = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    expect(substr($content, 0, 2))->toBe('PK');
    $file = tempnam(sys_get_temp_dir(), 'v48-excel-');
    if ($file === false) {
        throw new RuntimeException('Não foi possível criar XLSX temporário.');
    }
    try {
        file_put_contents($file, $content);
        return (new Xlsx)->load($file);
    } finally {
        @unlink($file);
    }
}

it('uses the same eight-column list for a personal pipeline stage without disclosing another portfolio', function (): void {
    v48Stage();
    $seller = v48User('V48 Vendedor Principal', User::ROLE_SELLER);
    $other = v48User('V48 Vendedor Externo', User::ROLE_SELLER);
    v48Deal($seller, '48480001', 'V48 EMPRESA DA CARTEIRA', '4848001001');
    v48Deal($other, '48480002', 'V48 EMPRESA DE OUTRO', '4848001002');

    $page = Livewire::actingAs($seller)->test('pages::leads.index');
    $index = collect($page->get('crmStageOptions'))->pluck('label')->search('Reunião Realizada');
    expect($index)->not->toBeFalse();

    $page->call('applyCrmStageView', (int) $index)
        ->assertSet('personalHubSpotStage', 'Reunião Realizada')
        ->assertSet('crm', 'stage:Reunião Realizada')
        ->assertSee('Empresas para trabalhar')
        ->assertSee('V48 EMPRESA DA CARTEIRA')
        ->assertDontSee('V48 EMPRESA DE OUTRO')
        ->assertSee('lv13-list-head lf47-unified-head', false)
        ->assertSee('Minha carteira · Reunião Realizada')
        ->call('clearPersonalHubSpotStageView')
        ->assertSet('personalHubSpotStage', '')
        ->assertSet('crm', '');
});

it('limits personal-stage Excel to selected deals in the authenticated portfolio', function (): void {
    v48Stage();
    $manager = v48User('V48 Gestor', User::ROLE_MANAGER);
    $other = v48User('V48 Outro', User::ROLE_SELLER);
    $mine1 = v48Deal($manager, '48480003', 'V48 CLIENTE A', '4848001003');
    v48Deal($manager, '48480004', 'V48 CLIENTE B', '4848001004');
    $unauthorized = v48Deal($other, '48480005', 'V48 OUTRO CLIENTE', '4848001005');

    $page = Livewire::actingAs($manager)->test('pages::leads.index');
    $index = collect($page->get('crmStageOptions'))->pluck('label')->search('Reunião Realizada');
    expect($index)->not->toBeFalse();

    $page->call('applyCrmStageView', (int) $index)
        ->set('selectedGlobalDealIds', [(string) $mine1->id, (string) $unauthorized->id]);
    $book = v48Workbook($page->instance()->exportExcel(app(LeadExportService::class)));
    try {
        $sheet = $book->getActiveSheet();
        // EC_PROSPECTOR_V51_1_HEADER_BASED_ASSERTIONS:
        // O layout ganhou Nome do contato. A posição pode mudar novamente;
        // validar os dados pelo cabeçalho é mais estável que Q2/R2 fixos.
        $headers = $sheet->rangeToArray(
            'A1:'.$sheet->getHighestDataColumn().'1',
            null,
            false,
            false,
        )[0];
        $dealColumn = array_search('HubSpot Deal ID', $headers, true);
        $stageColumn = array_search('Etapas HubSpot', $headers, true);
        if (! is_int($dealColumn) || ! is_int($stageColumn)) {
            throw new RuntimeException('Cabeçalhos HubSpot não encontrados no XLSX.');
        }

        expect($sheet->getHighestDataRow())->toBe(2)
            ->and($sheet->getCell([$dealColumn + 1, 2])->getValue())->toBe('4848001003')
            ->and($sheet->getCell([$stageColumn + 1, 2])->getValue())->toBe('Reunião Realizada');
    } finally {
        $book->disconnectWorksheets();
    }
});

it('keeps the global stage and personal stage separated and moves only the clear filters button', function (): void {
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $partial = file_get_contents(resource_path('views/partials/leads-global-stage-list-v45.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));
    $pipeline = file_get_contents(resource_path('views/partials/leads-crm-stage-strip.blade.php'));

    expect($view)->toContain('private function personalHubSpotStageQuery()')
        ->toContain('public function personalHubSpotStageDeals()')
        ->toContain('clearPersonalHubSpotStageView')
        ->and($partial)->toContain('EC_PROSPECTOR_V48')
        ->toContain('lv13-list-head lf47-unified-head')
        ->and($pipeline)->toContain('wire:click="clearPersonalHubSpotStageView"')
        ->and($css)->toContain('EC_PROSPECTOR_V48_START')
        ->toContain('justify-content: flex-start !important');
});
