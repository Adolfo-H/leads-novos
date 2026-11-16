<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders only one 30-second polling directive for the Leads workspace', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $html = Livewire::actingAs($user)->test('pages::leads.index')->html();

    expect(substr_count($html, 'wire:poll.30s="$refresh"'))->toBe(1)
        ->and(substr_count($html, 'wire:poll.10s="$refresh"'))->toBe(0)
        ->and(substr_count($html, 'wire:poll.'))->toBe(1)
        ->and($html)->toContain('Leads');
});

it('keeps HubSpot status visible without a second poll on the health panel', function (): void {
    $blade = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));

    expect($blade)->toContain('LEADS_POLL_SINGLE_V249')
        ->toContain('hubSpotRealtimeHealth')
        ->toContain('lv14-hubspot-status')
        ->toContain('rf-hubspot-health')
        ->and(substr_count($blade, 'wire:poll.'))->toBe(1);
});
