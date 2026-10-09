<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('preserves HubSpot pipeline filtering but removes duplicate Details', function (): void {
    $pipeline = file_get_contents(resource_path('views/partials/leads-crm-stage-strip.blade.php'));
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    expect($pipeline)->toContain('applyCrmStageView')
        ->and($view)->toContain('lv23-actions')->not->toContain('x-show="detailsOpen"');
});

it('keeps supplementary company fields in database for the company dossier', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::query()->create([
        'cnpj_root' => '98399830', 'corporate_name' => 'Empresa V18 Informações Extras',
        'size_description' => 'EMPRESA DE PEQUENO PORTE',
    ]);
    $company->sdrScore()->create([
        'score' => 80, 'priority' => 'high', 'label' => 'Alta', 'is_eligible' => true,
        'is_provisional' => false, 'factors' => [], 'version' => 'v23', 'metadata' => [], 'calculated_at' => now(),
    ]);
    Livewire::actingAs($user)->test('pages::leads.index')
        ->assertSee('Empresa V18 Informações Extras')->assertSee('Dossiê');
    expect($company->fresh()->size_description)->toBe('EMPRESA DE PEQUENO PORTE');
});
