<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function finalWorkspaceManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
});

it('renders the compact commercial workspace without distributing leads', function () {
    $this->actingAs(finalWorkspaceManager())
        ->get(route('leads.management'))
        ->assertSuccessful()
        ->assertSee('ecgm-page', false)
        ->assertSee('Distribuição da carteira')
        ->assertSee('Balancear novos leads')
        ->assertSee('Gestores e vendedores')
        ->assertDontSee('ec-intelligence-card', false);

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('keeps sellers out of commercial management', function () {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    $this->actingAs($seller)
        ->get(route('leads.management'))
        ->assertForbidden();
});

it('selects and clears distribution participants without changing their roles', function () {
    $user = finalWorkspaceManager();

    Livewire::actingAs($user)
        ->test('pages::leads.management')
        ->call('selectAllDistributionSellers')
        ->assertSet('distributionSellerIds', [$user->id])
        ->call('clearDistributionSellers')
        ->assertSet('distributionSellerIds', []);

    expect($user->refresh()->commercial_role)
        ->toBe(User::ROLE_MANAGER);
});

it('renders profile data with the original profile controls', function () {
    $user = finalWorkspaceManager();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertSuccessful()
        ->assertSee('ecui-settings', false)
        ->assertSee('Dados do perfil')
        ->assertSee($user->name)
        ->assertSee('updateProfileInformation', false)
        ->assertDontSee('ec-settings-hero-visual', false);
});

it('preserves validation of invalid profile email', function () {
    $user = finalWorkspaceManager();
    $previousEmail = $user->email;

    Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->set('email', 'email-invalido')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email']);

    expect($user->refresh()->email)->toBe($previousEmail);
});

it('renders the three appearance choices without altering account data', function () {
    $user = finalWorkspaceManager();

    $this->actingAs($user)
        ->get(route('appearance.edit'))
        ->assertSuccessful()
        ->assertSee('Claro')
        ->assertSee('Escuro')
        ->assertSee('Sistema')
        ->assertSee('x-model="$flux.appearance"', false)
        ->assertDontSee('ec-settings-hero-visual', false);

    Http::assertNothingSent();
});
