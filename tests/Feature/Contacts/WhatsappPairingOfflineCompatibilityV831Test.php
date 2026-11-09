<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('keeps the old expiring pairing request protocol even when the optional Ubuntu connector is offline', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    Cache::forget('prospector:wa:pair:v13_1:connector:heartbeat');

    Livewire::actingAs($user)->test('pages::contacts.whatsapp')
        ->call('requestWebPairing', 1, false)
        ->assertHasNoErrors();

    $request = Cache::get('prospector:wa:pair:v13_1:request:1');
    expect($request)->toBeArray()
        ->toHaveKey('token')->toHaveKey('reset')->toHaveKey('requested_at')
        ->and($request['reset'])->toBeFalse();

    $view = file_get_contents(resource_path('views/contacts/whatsapp-pairing-v13-1.blade.php'));
    expect($view)->not->toBeFalse()
        ->toContain('! $wa131ConnectorUp')
        ->toContain('recuperar-conector-v83.sh');
});
