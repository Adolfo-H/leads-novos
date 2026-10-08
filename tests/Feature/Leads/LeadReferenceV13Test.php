<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('preserves the lead workflows and renders the compact V13 interface', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $company = Company::query()->create([
        'cnpj_root' => '77997799',
        'corporate_name' => 'Empresa Referencia V13 Teste',
        'source' => 'test',
    ]);
    $company->sdrScore()->create([
        'score' => 86,
        'priority' => 'high',
        'label' => 'Prioridade alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'test',
        'metadata' => [],
        'calculated_at' => now(),
    ]);

    Livewire::actingAs($manager)
        ->test('pages::leads.index')
        ->assertSee('Empresa Referencia V13 Teste')
        ->assertSee('Atrasados')
        ->assertSee('Pipeline HubSpot')
        ->assertDontSee('Filtros avançados')
        ->assertSee('Exportar Excel')
        ->assertSee('Selecionar Empresa Referencia V13 Teste')
        ->assertSeeHtml('lv13-lead-line')
        ->assertSeeHtml('lv13-details')
        ->assertSee('Abrir dossiê');
});

it('receives the topbar search and applies the original Lead search property', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    Livewire::actingAs($manager)->test('pages::leads.index')
        ->call('leadsTopbarSearch', '  Empresa teste  ')
        ->assertSet('search', 'Empresa teste');
});

it('keeps attribution, CRM filtering, export, and source code of the old expanded controls', function (): void {
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $css = file_get_contents(resource_path('css/leads-reference-v13.css'));
    $pipeline = file_get_contents(resource_path('views/partials/leads-crm-stage-strip.blade.php'));

    expect($view)
        ->toContain('leads-v13')
        ->toContain('lv13-details')
        ->toContain('lv13-lead-line')
        ->toContain('wire:click="exportExcel"')
        ->toContain('private function filteredLeadsQuery()')
        ->toContain('wire:model.live="owner"')
        ->toContain('wire:model.live="crm"')
        ->toContain('assignOwner(')
        ->toContain("#[On('leads-topbar-search')]")
        ->toContain('verifyHubSpotNow(')
        ->toContain('claimLead(')
        ->toContain('rf-col-action');

    expect($css)
        ->toContain('.lv13-lead-line')
        ->toContain('.lv13-details')
        ->toContain('.lv12-card')
        ->toContain('@media(max-width:600px)');
    expect($pipeline)->toContain('applyCrmStageView')->toContain('lv13-stage--');
});
