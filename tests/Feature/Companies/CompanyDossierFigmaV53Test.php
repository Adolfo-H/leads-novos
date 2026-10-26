<?php

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the five real Figma E05–E09 screens without fictitious company data', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = app(CompanyService::class)->createOrUpdateFromEstablishment(
        ['corporate_name' => 'Empresa Visual V53'],
        ['cnpj' => '11.222.333/0001-81', 'type' => 'matrix', 'registration_status' => 'ATIVA', 'state' => 'PR'],
        [['code' => '4622200', 'description' => 'Comércio atacadista de soja', 'is_primary' => true]],
    );

    Livewire::actingAs($user)->test('pages::companies.show', ['company' => $company])
        ->assertSee('data-ec-dossier-figma="E05-E09"', false)
        ->assertSee('Empresa Visual V53')
        ->assertSee('Leitura comercial')
        ->assertSee('Relacionamento no HubSpot')
        ->assertSee('Histórico, tarefas e evidências')
        ->assertSee('Identificação da empresa')
        ->assertSee('Atividades secundárias')
        ->assertSee('4622200')
        ->assertDontSee('Serra Verde Grãos');
});

it('preserves the original dossier services, HubSpot actions and CNAE forms in an accessible advanced section', function (): void {
    $show = file_get_contents(resource_path('views/pages/companies/⚡show.blade.php'));
    $figma = file_get_contents(resource_path('views/partials/company-dossier/figma-v53.blade.php'));
    $modals = file_get_contents(resource_path('views/partials/company-dossier/figma-modals-v53.blade.php'));

    expect($show)->toContain('EC_PROSPECTOR_E53')
        ->toContain('showAdvancedDossier')
        ->toContain('partials.company-dossier.commercial')
        ->toContain('partials.company-dossier.company')
        ->toContain('partials.company-dossier.cnaes')
        ->and($figma)->toContain('figma-summary-v53')
        ->toContain('figma-crm-v53')
        ->toContain('figma-history-v53')
        ->toContain('figma-group-v53')
        ->toContain('figma-cnaes-v53')
        ->and($modals)->toContain('wire:click="researchExports"')
        ->toContain('wire:click="researchExportsForce"')
        ->toContain('wire:submit="addCnae"')
        ->toContain('wire:click="closeHubSpotOpportunityProgress"');
});

it('retains permissions and does not change the Dashboard or Leads CSS', function (): void {
    $stylesheet = file_get_contents(resource_path('css/company-dossier-figma-v53.css'));
    $appStylesheet = file_get_contents(resource_path('css/app.css'));
    $cnaes = file_get_contents(resource_path('views/partials/company-dossier/figma-cnaes-v53.blade.php'));
    $figma = file_get_contents(resource_path('views/partials/company-dossier/figma-v53.blade.php'));

    expect($stylesheet)->toContain('.ec-dossier-page.e53-dossier')
        ->and($appStylesheet)->toContain("@import './company-dossier-figma-v53.css';")
        ->and($cnaes)->toContain("@can('manageData', \$company)")
        ->and($figma)->toContain("@can('createHubSpotOpportunity', \$company)")
        ->toContain("'hubSpotCompanies.commercialDeals'"); // Relação fiscal já aplica TrustedFiscalLink no Model.
});
