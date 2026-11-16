<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps only Gestao comercial in the topbar and removes the Enter hint on that route', function (): void {
    $manager = User::factory()->create([
        'commercial_role' => User::ROLE_MANAGER,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($manager)->get(route('leads.management'));

    $response->assertOk()
        ->assertSee('Gestão comercial')
        ->assertSee('data-ecmg-topbar-search="v255"', false)
        ->assertDontSee('<span class="ec-topbar-eyebrow">', false)
        ->assertDontSee('<kbd aria-hidden="true">Enter</kbd>', false);
});

it('preserves the global search Enter hint outside the management route', function (): void {
    $sidebar = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));
    $search = file_get_contents(resource_path('views/components/global-company-search.blade.php'));

    expect($sidebar)->toContain("'leads.index', 'leads.management', 'companies.show'")
        ->and($search)->toContain("@unless (request()->routeIs('leads.management', 'prospecting.*'))")
        ->toContain('<kbd aria-hidden="true">Enter</kbd>');
});
