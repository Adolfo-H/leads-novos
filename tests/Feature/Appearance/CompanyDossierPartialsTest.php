<?php

use App\Models\User;
use App\Services\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('uses the active dossier entry point and three real tabs', function (): void {
    $show = file_get_contents(resource_path('views/pages/companies/⚡show.blade.php'));
    $figma = file_get_contents(resource_path('views/partials/company-dossier/figma-v53.blade.php'));

    expect($show)->toContain("@include('partials.company-dossier.figma-v53')")
        ->and($figma)->toContain('aria-label="Abas do dossiê empresarial"')
        ->toContain("setDossierTab('commercial')")
        ->toContain("setDossierTab('company')")
        ->toContain("setDossierTab('cnaes')")
        ->toContain("@include('partials.company-dossier.figma-summary-v53')")
        ->toContain("@include('partials.company-dossier.figma-group-v53')")
        ->toContain("@include('partials.company-dossier.figma-cnaes-v53')");
});

it('keeps commercial evidence group information and matrix CNAE actions in active partials', function (): void {
    $summary = file_get_contents(resource_path('views/partials/company-dossier/figma-summary-v53.blade.php'));
    $group = file_get_contents(resource_path('views/partials/company-dossier/figma-group-v53.blade.php'));
    $cnaes = file_get_contents(resource_path('views/partials/company-dossier/figma-cnaes-v53.blade.php'));
    $modals = file_get_contents(resource_path('views/partials/company-dossier/figma-modals-v53.blade.php'));

    expect($summary)->toContain('Evidências de exportação')
        ->and($group)->toContain('CNAEs consolidados do grupo')
        ->toContain('e53-contact-extra')
        ->and($cnaes)->toContain('makeCnaePrimary')
        ->toContain("@can('manageData', \$company)")
        ->and($modals)->toContain('wire:submit="addCnae"')
        ->toContain('$wire.removeCnae');
});

it('renders the active dossier without depending on deleted legacy partials', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = app(CompanyService::class)->createOrUpdateFromEstablishment(
        ['corporate_name' => 'Empresa Dossie A11'],
        ['cnpj' => '11.222.333/0001-81', 'type' => 'matrix', 'registration_status' => 'ATIVA', 'state' => 'PR'],
        [['code' => '4622200', 'description' => 'Comércio atacadista de soja', 'is_primary' => true]],
    );

    Livewire::actingAs($user)->test('pages::companies.show', ['company' => $company])
        ->assertSee('data-ec-dossier-figma="E05-E09"', false)
        ->assertSee('Empresa Dossie A11')
        ->assertSee('Abas do dossiê empresarial')
        ->assertSee('Relacionamento no HubSpot')
        ->assertSee('Identificação da empresa')
        ->assertSee('Atividades secundárias')
        ->assertSee('4622200');
});

it('does not keep the three superseded dossier templates', function (): void {
    foreach ([
        'commercial.blade.php',
        'company.blade.php',
        'cnaes.blade.php',
    ] as $filename) {
        expect(is_file(resource_path('views/partials/company-dossier/'.$filename)))->toBeFalse();
    }
});
