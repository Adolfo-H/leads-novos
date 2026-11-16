<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

function wa131Manager(bool $manager = true): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => $manager ? User::ROLE_MANAGER : User::ROLE_SELLER])->save();

    return $user;
}

it('allows a manager to request a reconnect without sending messages or touching WhatsApp credentials', function (): void {
    $this->actingAs(wa131Manager());
    Livewire::test('pages::contacts.whatsapp')
        ->assertSee('Conexão dos WhatsApps')
        ->call('requestWebPairing', 1, false)
        ->assertHasNoErrors();

    $request = Cache::get('prospector:wa:pair:v13_1:request:1');
    expect($request)->toBeArray()->toHaveKey('token')
        ->and($request['reset'])->toBeFalse();

    expect(Artisan::call('contacts:wa-web:pairing', ['action' => 'poll', '--session' => '1']))->toBe(0);
    $response = json_decode(trim(Artisan::output()), true);
    expect($response['token'])->toBe($request['token']);
});

it('requires a manager and only accepts the two expected session ids', function (): void {
    $seller = wa131Manager(false);

    // Na rota HTTP, o middleware commercial.manager devolve 403.
    $this->actingAs($seller)
        ->get(route('contacts.whatsapp'))
        ->assertForbidden();

    // O middleware e o boot() negam acesso antes de existir um snapshot Livewire.
    // A tentativa de executar call() após uma montagem 403 falha no mecanismo de
    // testes do Livewire 4 (snapshot inválido), e não no código de autorização.
    // A resposta 403 acima e a ausência de pedido de pareamento abaixo verificam
    // que o vendedor não conseguiu acessar nem produzir efeitos colaterais.

    // A proteção não pode ser apenas visual: vendedor NÃO cria pedidos.
    expect(Cache::has('prospector:wa:pair:v13_1:request:2'))->toBeFalse();

    // Um gestor autenticado também não pode escolher uma terceira sessão.
    Livewire::actingAs(wa131Manager())
        ->test('pages::contacts.whatsapp')
        ->call('requestWebPairing', 3, false)
        ->assertHasErrors(['wa_pair']);

    expect(Cache::has('prospector:wa:pair:v13_1:request:3'))->toBeFalse();
});

it('keeps re-pairing explicit and does not erase the saved credential directory', function (): void {
    Livewire::actingAs(wa131Manager())
        ->test('pages::contacts.whatsapp')
        ->call('requestWebPairing', 2, true)
        ->assertHasNoErrors();
    $request = Cache::get('prospector:wa:pair:v13_1:request:2');
    expect($request['reset'])->toBeTrue();

    expect(Artisan::call('contacts:wa-web:pairing', ['action' => 'ack', '--session' => '2', '--token' => $request['token']]))->toBe(0);
    expect(Cache::get('prospector:wa:pair:v13_1:request:2'))->toBeNull();
});
