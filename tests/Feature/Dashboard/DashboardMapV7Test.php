<?php

use App\Livewire\DashboardOverview;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders interactive vector geometry for the 27 Brazilian states', function (): void {
    $map = file_get_contents(resource_path('views/livewire/dashboard-overview/map-interactive-v7.blade.php'));
    expect($map)->not->toBeFalse()
        ->and(substr_count($map, 'class="ds-v7-state"'))->toBe(27)
        ->and($map)->toContain('data-uf="SP"')
        ->toContain('data-uf="PR"')
        ->toContain('data-uf="DF"')
        ->toContain('x-on:mouseenter=')
        ->toContain('wire:click="openExplorer');
});

it('shows real state totals and percentages, including states outside the top five', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $company = Company::query()->create([
        'cnpj_root' => '99887766',
        'corporate_name' => 'Empresa teste mapa V7',
    ]);

    foreach (['SP', 'SP', 'PR', null] as $index => $uf) {
        Establishment::query()->create([
            'company_id' => $company->id,
            'cnpj' => '99887766'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT).'00',
            'order_number' => str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
            'check_digits' => '00',
            'type' => $index === 0 ? 'matrix' : 'branch',
            'registration_status' => 'ATIVA',
            'state' => $uf,
            'source' => 'test',
        ]);
    }

    $page = Livewire::actingAs($manager)->test(DashboardOverview::class);

    expect($page->get('stateMapCounts'))
        ->toMatchArray(['SP' => 2, 'PR' => 1]);

    $page
        ->assertSee('ds-v7-map-svg')
        ->assertSee('São Paulo: 2 estabelecimentos (50,0% da base)')
        ->assertSee('Paraná: 1 estabelecimento (25,0% da base)');
});

it('shows only one decorative sidebar world, without previous duplicated cards', function (): void {
    $source = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));
    expect(substr_count($source, 'class="ec-sidebar-world-v7"'))->toBe(1)
        ->and($source)->not->toContain('class="ec-sidebar-world"')
        ->not->toContain('class="ec-sidebar-world-card"');

    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $this->actingAs($manager)->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('sidebar-world-v7.webp');
});

it('does not expose detailed state map to sellers', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    Livewire::actingAs($seller)
        ->test(DashboardOverview::class)
        ->assertDontSee('ds-v7-map-svg')
        ->assertDontSee('Top oportunidades por CNAE');
});
