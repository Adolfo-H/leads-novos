<?php

use App\Livewire\DashboardOverview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows commercial indicators to managers without external requests', function () {
    Http::preventStrayRequests();

    $manager = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $manager->forceFill([
        'commercial_role' => User::ROLE_MANAGER,
    ])->save();

    $page = Livewire::actingAs($manager)
        ->test(DashboardOverview::class)
        ->assertSee('Dashboard estratégico')
        ->assertSee('Leads cadastrados')
        ->assertSee('Prioridades comerciais')
        ->assertSee('Cobertura cadastral');

    $summary = $page->get('commercialSummary');

    expect($summary)
        ->toBeArray()
        ->toHaveKey('active_total')
        ->toHaveKey('attention_total');

    Http::assertNothingSent();
});

it('does not expose team metrics to sellers', function () {
    $seller = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $seller->forceFill([
        'commercial_role' => User::ROLE_SELLER,
    ])->save();

    $page = Livewire::actingAs($seller)
        ->test(DashboardOverview::class)
        ->assertSee('Minha fila de leads')
        ->assertDontSee('Top oportunidades por CNAE')
        ->assertDontSee('Gestão comercial');

    expect($page->get('commercialSummary'))->toBeNull();
});
