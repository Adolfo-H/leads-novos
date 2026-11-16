<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('removes expandable Details without removing real commercial operations', function (): void {
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    expect($view)->toContain('lv23-actions')
        ->toContain('wire:click="exportExcel"')
        ->toContain('assignOwner(')->toContain('verifyHubSpotNow(')
        ->toContain('claimLead(')->toContain('resumeLead(')
        ->not->toContain('x-show="detailsOpen"');
});

it('preserves source metadata after eliminating redundant panels', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $company = Company::query()->create(['cnpj_root' => '98008020', 'corporate_name' => 'Empresa V20']);
    $company->sdrScore()->create([
        'score' => 78, 'priority' => 'high', 'label' => 'Alta', 'is_eligible' => true,
        'is_provisional' => false, 'factors' => [], 'version' => 'v23', 'metadata' => [], 'calculated_at' => now(),
    ]);
    $company->leadWorkState()->create(['assigned_user_id' => $manager->id, 'status' => 'new', 'note' => 'Não apagar esta nota']);
    Livewire::actingAs($manager)->test('pages::leads.index')->assertSee('Empresa V20')->assertSee('Dossiê');
    expect($company->fresh()->leadWorkState?->note)->toBe('Não apagar esta nota');
});
