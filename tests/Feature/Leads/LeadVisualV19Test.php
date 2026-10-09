<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('opens the company dossier instead of duplicating its supplementary data', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::query()->create([
        'cnpj_root' => '98456789', 'corporate_name' => 'Empresa Pesquisa V19',
        'size_description' => 'PEQUENO PORTE', 'share_capital' => '150000.00',
    ]);
    $company->sdrScore()->create([
        'score' => 81, 'priority' => 'high', 'label' => 'Alta', 'is_eligible' => true,
        'is_provisional' => false, 'factors' => [], 'version' => 'v23', 'metadata' => [], 'calculated_at' => now(),
    ]);
    $company->leadWorkState()->create(['assigned_user_id' => $manager->id, 'status' => 'new', 'note' => 'Nota V19 preservada']);
    Livewire::actingAs($manager)->test('pages::leads.index')
        ->assertSee('Empresa Pesquisa V19')->assertSee('Dossiê')->assertDontSee('Informações complementares');
    expect($company->fresh()->leadWorkState?->note)->toBe('Nota V19 preservada')
        ->and($company->fresh()->size_description)->toBe('PEQUENO PORTE');
});
