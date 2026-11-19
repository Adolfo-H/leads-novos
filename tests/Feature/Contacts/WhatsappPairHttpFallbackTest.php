<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function waHttpV1Actor(bool $manager = true): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => $manager ? User::ROLE_MANAGER : User::ROLE_SELLER])->save();

    return $user;
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    Cache::forget('prospector:wa:pair:v13_1:connector:heartbeat');
    foreach ([1, 2] as $session) {
        Cache::forget('prospector:wa:pair:v13_1:request:'.$session);
        Cache::forget('prospector:wa:pair:v13_1:state:'.$session);
        Cache::forget('prospector:wa:pair:v13_1:qr:'.$session);
    }
});

it('cria por POST a mesma solicitacao legada sem Livewire ou mensagens', function (): void {
    Cache::put('prospector:wa:pair:v13_1:connector:heartbeat', now('UTC')->toIso8601String(), now()->addMinute());

    $this->actingAs(waHttpV1Actor())
        ->post(route('contacts.whatsapp.pair-http-v1'), ['session' => '1', 'reset' => '0'])
        ->assertRedirect(route('contacts.whatsapp', ['aba' => 'operation']).'#wa12-operation')
        ->assertSessionHas('wa_pair_notice');

    $request = Cache::get('prospector:wa:pair:v13_1:request:1');
    expect($request)->toBeArray()
        ->toHaveKey('token')->toHaveKey('reset')->toHaveKey('actor_id')->toHaveKey('requested_at')
        ->and(strlen((string) $request['token']))->toBe(40)
        ->and($request['reset'])->toBeFalse();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('permite gerar novo QR somente como acao explicita do gestor', function (): void {
    Cache::put('prospector:wa:pair:v13_1:connector:heartbeat', now('UTC')->toIso8601String(), now()->addMinute());
    $this->actingAs(waHttpV1Actor())
        ->post(route('contacts.whatsapp.pair-http-v1'), ['session' => '2', 'reset' => '1'])
        ->assertSessionHas('wa_pair_notice');

    $request = Cache::get('prospector:wa:pair:v13_1:request:2');
    expect($request['reset'])->toBeTrue()
        ->and(Cache::has('prospector:wa:pair:v13_1:request:1'))->toBeFalse();
    Mail::assertNothingSent();
});

it('informa conector offline sem criar pedido que nao pode ser processado', function (): void {
    $this->actingAs(waHttpV1Actor())
        ->post(route('contacts.whatsapp.pair-http-v1'), ['session' => '1', 'reset' => '0'])
        ->assertSessionHas('wa_pair_notice_error');

    expect(Cache::has('prospector:wa:pair:v13_1:request:1'))->toBeFalse();
});

it('bloqueia vendedor, visitante e sessao invalida', function (): void {
    Cache::put('prospector:wa:pair:v13_1:connector:heartbeat', now('UTC')->toIso8601String(), now()->addMinute());

    $this->post(route('contacts.whatsapp.pair-http-v1'), ['session' => 1, 'reset' => 0])
        ->assertRedirect(route('login'));
    $this->actingAs(waHttpV1Actor(false))
        ->post(route('contacts.whatsapp.pair-http-v1'), ['session' => 1, 'reset' => 0])
        ->assertForbidden();
    $this->actingAs(waHttpV1Actor())
        ->post(route('contacts.whatsapp.pair-http-v1'), ['session' => 3, 'reset' => 0])
        ->assertSessionHasErrors('session');
    expect(Cache::has('prospector:wa:pair:v13_1:request:1'))->toBeFalse();
});

it('nao substitui uma solicitacao em andamento por outra', function (): void {
    Cache::put('prospector:wa:pair:v13_1:connector:heartbeat', now('UTC')->toIso8601String(), now()->addMinute());
    $this->actingAs(waHttpV1Actor())
        ->post(route('contacts.whatsapp.pair-http-v1'), ['session' => 1, 'reset' => 0]);
    $before = Cache::get('prospector:wa:pair:v13_1:request:1');
    $this->post(route('contacts.whatsapp.pair-http-v1'), ['session' => 1, 'reset' => 1]);
    $after = Cache::get('prospector:wa:pair:v13_1:request:1');
    expect($before['token'])->toBe($after['token'])
        ->and($after['reset'])->toBeFalse();
});

it('renderiza formularios POST e atualizacao de QR sem depender de cliques Livewire', function (): void {
    $actor = waHttpV1Actor();
    $this->actingAs($actor)->get(route('contacts.whatsapp', ['aba' => 'operation']))
        ->assertOk()->assertSee('Conexão dos WhatsApps')
        ->assertSee('parear-v1')
        ->assertSee('Atualizar QR e estado');

    $view = file_get_contents(resource_path('views/contacts/whatsapp-pairing.blade.php'));
    expect($view)->toContain('route(\'contacts.whatsapp.pair-http-v1\')')
        ->toContain('method="POST"')
        ->toContain('@csrf');
});
