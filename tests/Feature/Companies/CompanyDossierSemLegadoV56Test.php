<?php

use App\Models\User;
use App\Services\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders only the redesigned dossier E05-E09 and never emits the legacy block on any tab', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = app(CompanyService::class)->createOrUpdateFromEstablishment(
        ['corporate_name' => 'Empresa Dossiê Moderno V56'],
        ['cnpj' => '11.222.333/0001-81', 'type' => 'matrix', 'registration_status' => 'ATIVA', 'state' => 'PR'],
        [['code' => '4622200', 'description' => 'Comércio atacadista de soja', 'is_primary' => true]],
    );

    Livewire::actingAs($user)->test('pages::companies.show', ['company' => $company])
        ->assertSee('data-ec-dossier-figma="E05-E09"', false)
        ->assertSee('Empresa Dossiê Moderno V56')
        ->assertSee('Leitura comercial')
        ->assertSee('Relacionamento no HubSpot')
        ->assertSee('Histórico, tarefas e evidências')
        ->assertSee('Identificação da empresa')
        ->assertSee('Atividades secundárias')
        ->assertSee('Pesquisar exportações')
        ->assertDontSee('e53-legacy', false)
        ->assertDontSee('showAdvancedDossier', false)
        ->assertDontSee('Ocultar funcionalidades avançadas')
        ->assertDontSee('Ver funcionalidades avançadas')
        ->assertDontSee('Pesquisa de exportação disponível')
        ->assertDontSee('Pesquisar mesmo assim');
});

it('keeps new contact expansion buttons and removes obsolete CSS selectors', function (): void {
    $group = file_get_contents(resource_path('views/partials/company-dossier/figma-group-v53.blade.php'));
    $styles = file_get_contents(resource_path('css/company-dossier-figma-v53.css'));
    $figma = file_get_contents(resource_path('views/partials/company-dossier/figma-v53.blade.php'));
    $modals = file_get_contents(resource_path('views/partials/company-dossier/figma-modals-v53.blade.php'));

    expect($group)->toContain('e53-contact-extra')
        ->toContain('Ver todos')
        ->toContain('showAll = !showAll')
        ->not->toContain('showAdvancedDossier')
        ->not->toContain('Funcionalidades avançadas')
        ->and($styles)->toContain('PROSPECTOR_DOSSIE_V56')
        ->not->toContain('.e53-legacy')
        ->not->toContain('.e53-footer')
        ->and($figma)->toContain('e53HubSpotCompanyUrl')
        ->toContain('Abrir empresa no HubSpot')
        ->and($modals)->toContain('establishmentAddress($unit)')
        ->toContain('sourceUpdatedAt')
        ->toContain('specialSituation')
        ->toContain('statusSince');
});
