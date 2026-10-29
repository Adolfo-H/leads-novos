<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('maintains real management features in the redesigned commercial view', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    Livewire::actingAs($manager)
        ->test('pages::leads.management')
        ->assertSee('Gestão Comercial')
        ->assertSee('Oportunidades ativas')
        ->assertSee('Carga e pendências por vendedor')
        ->assertSee('Distribuição da carteira')
        ->assertSee('Distribuir automaticamente')
        ->assertSee('Gestores e vendedores')
        ->assertSee('Ver prioridades');

    // Acoes por empresa so aparecem quando existe uma pendencia.
    // A renderizacao com dados e coberta por CommercialManagementAttentionQueueTest.
    $attentionPartial = file_get_contents(
        resource_path('views/partials/commercial-attention-queue.blade.php')
    );

    expect($attentionPartial)
        ->toContain('Abrir dossiê')
        ->toContain('Ver fila');
});

it('keeps the attention queue accessible without occupying the first screen', function (): void {
    $blade = file_get_contents(resource_path('views/pages/leads/⚡management.blade.php'));

    expect($blade)
        ->toContain('ecgm-page ecgm-v2')
        ->toContain('wire:poll.visible.30s')
        ->toContain('<details class="ecgm-v2-attention-drawer ecgm62-attention-details" open>')
        ->toContain('ecgm62-attention-table')
        ->toContain('ecgm-team-focus-grid')
        ->toContain('ecgm-management-table')
        ->toContain('ecgm-v2-table-action')
        ->toContain('partials.commercial-distribution-preview-v61')
        ->toContain('wire:click="autoDistribute"')
        ->toContain('wire:click="updateCommercialRole');
});

it('loads the commercial visual styles and respects responsive and reduced motion', function (): void {
    $appCss = file_get_contents(resource_path('css/app.css'));
    $v2 = file_get_contents(resource_path('css/commercial-management-consolidated.css'));
    $icons = file_get_contents(resource_path('views/partials/commercial-management-icon.blade.php'));

    expect($appCss)->toContain("@import './commercial-management-consolidated.css';")
        ->and($v2)->toContain('.ecgm-page.ecgm-v2')
        ->toContain('max-width: none;')
        ->toContain('@media (max-width: 1200px)')
        ->toContain('@media (max-width: 610px)')
        ->toContain('prefers-reduced-motion: reduce')
        ->and($icons)->toContain("@case('attention_total')")
        ->toContain("@case('opportunities_total')");
});

it('still denies management to non-managers', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller)->test('pages::leads.management')->assertStatus(403);
});
