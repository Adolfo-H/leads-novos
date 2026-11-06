<?php

use App\Models\User;
use App\Services\Contacts\WhatsappPhoneRegistry;
use App\Services\Contacts\WhatsappWebControlPanel;
use App\Services\Contacts\WhatsappWebDailyCoordinator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function wa8Manager(): User
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    return $actor;
}

function wa8Phone(string $digits, string $kind, ?string $e164 = null): int
{
    return (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'test:'.$digits,
        'digits' => $digits,
        'e164' => $e164,
        'kind' => $kind,
        'status' => 'unknown',
        'contacts_count' => 1,
        'occurrences_count' => 1,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
}

it('abre todos os numeros do cadastro e oferece paginacao e filtros por categoria', function () {
    wa8Phone('5545999991001', 'mobile', '5545999991001');
    wa8Phone('554532345555', 'landline', '554532345555');
    wa8Phone('32345555', 'review');

    $this->actingAs(wa8Manager())->get(route('contacts.whatsapp'))
        ->assertOk()
        ->assertSee('Lista completa de números')
        ->assertSee('Todos os números')
        ->assertSee('Revisar formato')
        ->assertSee('100 números')
        ->assertDontSee('<kbd aria-hidden="true">Enter</kbd>', false);

    expect(app(WhatsappPhoneRegistry::class)->search('', 'all', 'all', 'all', 1)->total())->toBe(3);
    expect(app(WhatsappPhoneRegistry::class)->search('', 'mobile', 'all', 'all', 1)->total())->toBe(1);
    expect(app(WhatsappPhoneRegistry::class)->search('', 'landline', 'all', 'all', 1)->total())->toBe(1);
    expect(app(WhatsappPhoneRegistry::class)->search('', 'review', 'all', 'all', 1)->total())->toBe(1);
});

it('pagina 50 ou 100 registros sem carregar todo o banco em memoria', function () {
    for ($i = 0; $i < 55; $i++) {
        wa8Phone('5545'.str_pad((string) (900000000 + $i), 9, '0', STR_PAD_LEFT), 'mobile', '5545'.(900000000 + $i));
    }
    $repo = app(WhatsappPhoneRegistry::class);
    $one = $repo->search('', 'all', 'all', 'all', 1, 'all', 50);
    $two = $repo->search('', 'all', 'all', 'all', 2, 'all', 50);
    expect($one->total())->toBe(55)
        ->and($one->items())->toHaveCount(50)
        ->and($two->items())->toHaveCount(5);
});

it('permite aumentar o limite para 100 sem ativar agendamento automaticamente', function () {
    $coordinator = app(WhatsappWebDailyCoordinator::class);
    $actor = wa8Manager();
    $coordinator->save($actor, 100, '08:30', false, 'all');
    $settings = $coordinator->settings();
    expect((int) $settings->daily_limit)->toBe(100)
        ->and((string) $settings->scan_scope)->toBe('all')
        ->and((bool) $settings->auto_enabled)->toBeFalse();
    expect(fn () => $coordinator->save($actor, 201, '08:30', false, 'all'))
        ->toThrow(ValidationException::class);
});

it('permite solicitar lotes de fixos sem incluir celulares ou numeros para revisar', function () {
    $actor = wa8Manager();
    $fixed = wa8Phone('554532345678', 'landline', '554532345678');
    wa8Phone('5545999998777', 'mobile', '5545999998777');
    wa8Phone('12345678', 'review');
    $session = $fixed % 2 === 0 ? 1 : 2;
    DB::table('whatsapp_web_scan_agents')->where('session', $session)->update([
        'connection_state' => 'connected', 'last_heartbeat_at' => now('UTC'),
    ]);
    $id = app(WhatsappWebControlPanel::class)->request($actor, $session, 5, 'landline');
    $batch = DB::table('whatsapp_web_scan_batches')->where('id', $id)->first();
    expect((string) $batch->scan_scope)->toBe('landline')
        ->and((int) $batch->requested_limit)->toBe(1);
    expect(Artisan::call('contacts:wa-web:candidates', [
        '--limit' => 10, '--fixed-only' => true, '--slot' => (string) ($session === 1 ? 0 : 1),
    ]))->toBe(0);
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    expect((int) $payload['count'])->toBe(1)
        ->and((string) $payload['candidates'][0]['kind'])->toBe('landline');
});
