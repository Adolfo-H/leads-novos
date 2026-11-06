<?php

use App\Models\User;
use App\Services\Contacts\WhatsappWebConfirmationService;
use App\Services\Contacts\WhatsappWebDailyCoordinator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function wa7Phone(string $digits, string $status = 'unknown'): int
{
    return (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'br:'.$digits,
        'digits' => $digits, 'e164' => $digits, 'kind' => 'mobile',
        'status' => $status, 'contacts_count' => 1, 'occurrences_count' => 1,
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
}

function wa7Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    return $user;
}

function wa7Source(int $phoneId, string $source): void
{
    DB::table('whatsapp_phone_sources')->insert([
        'phone_id' => $phoneId,
        'source_key' => $source,
        'origin' => 'hubspot', 'phone_field' => 'phone',
        'raw_digits' => (string) DB::table('whatsapp_phone_numbers')->where('id', $phoneId)->value('digits'),
        'contact_label' => 'Contato de teste',
        'scan_id' => (string) \Illuminate\Support\Str::uuid(),
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
}

it('promotes web positives to technical confirmation across the phone and contact directory', function () {
    $id = wa7Phone('5545999991011');
    wa7Source($id, 'hubspot:contacto-teste-v7');
    expect(Artisan::call('contacts:wa-web:record', [
        'phone_id' => $id, 'result' => 'found', '--session' => '1',
    ]))->toBe(0);
    $phone = DB::table('whatsapp_phone_numbers')->where('id', $id)->first();
    expect($phone->status)->toBe('yes')
        ->and($phone->evidence)->toBe('web_found_unofficial');
    expect(DB::table('contact_phone_verifications')
        ->where('source_key', 'hubspot:contacto-teste-v7')
        ->value('whatsapp_status'))->toBe('yes');
    expect(DB::table('whatsapp_phone_events')->where('phone_id', $id)->count())->toBe(1);
    expect(app(WhatsappWebConfirmationService::class)->promote($id))->toBeFalse();
    expect(DB::table('whatsapp_phone_events')->where('phone_id', $id)->count())->toBe(1);
});

it('does not override human negatives or auto-label a not-found result as absence', function () {
    $manual = wa7Phone('5545999992022', 'no');
    $negative = wa7Phone('5545999993033');
    expect(Artisan::call('contacts:wa-web:record', [
        'phone_id' => $manual, 'result' => 'found',
    ]))->toBe(0);
    expect(Artisan::call('contacts:wa-web:record', [
        'phone_id' => $negative, 'result' => 'not_found',
    ]))->toBe(0);
    expect(DB::table('whatsapp_phone_numbers')->where('id', $manual)->value('status'))->toBe('no')
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $negative)->value('status'))->toBe('unknown');
});

it('reconciles historic positives without duplicates', function () {
    $id = wa7Phone('5545999994044');
    DB::table('whatsapp_web_probe_results')->insert([
        'phone_id' => $id, 'result' => 'found', 'provider' => 'baileys_unofficial',
        'checked_at' => now('UTC'), 'attempts' => 1,
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
    expect(Artisan::call('contacts:wa-web:reconcile-found', ['--apply' => true]))->toBe(0);
    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('yes');
    expect(Artisan::call('contacts:wa-web:reconcile-found', ['--apply' => true]))->toBe(0);
    expect(DB::table('whatsapp_phone_events')->where('phone_id', $id)->count())->toBe(1);
});

it('keeps the automatic daily schedule disabled by default and allows managers to configure it', function () {
    $coordinator = app(WhatsappWebDailyCoordinator::class);
    expect((bool) $coordinator->settings()->auto_enabled)->toBeFalse();
    expect($coordinator->planDaily()['requested'])->toBe(0);
    $actor = wa7Manager();
    $coordinator->save($actor, 20, '09:15', true);
    expect((int) $coordinator->settings()->daily_limit)->toBe(20)
        ->and((string) $coordinator->settings()->start_time)->toBe('09:15')
        ->and((bool) $coordinator->settings()->auto_enabled)->toBeTrue();
    expect(fn () => $coordinator->save($actor, 200, '09:00', false))->toThrow(ValidationException::class);
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    expect(fn () => $coordinator->save($seller, 10, '08:30', false))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('limits two-account batch requests to the daily budget and keeps them disjoint', function () {
    $actor = wa7Manager();
    wa7Phone('5545999995055');
    wa7Phone('5545999996066');
    foreach ([1, 2] as $slot) {
        DB::table('whatsapp_web_scan_agents')->where('session', $slot)->update([
            'connection_state' => 'connected', 'last_heartbeat_at' => now('UTC'),
        ]);
    }
    $batchIds = app(WhatsappWebDailyCoordinator::class)->startBoth($actor, 5);
    expect(count($batchIds))->toBe(2);
    expect(DB::table('whatsapp_web_scan_batches')->sum('requested_limit'))->toBe(2);
    expect(fn () => app(WhatsappWebDailyCoordinator::class)->startBoth($actor, 5))
        ->toThrow(ValidationException::class);
});

it('renders one unified screen without duplicating the WhatsApp monitoring section', function () {
    $this->actingAs(wa7Manager())->get(route('contacts.whatsapp'))
        ->assertOk()
        ->assertSee('Acompanhe tudo em um só lugar')
        ->assertSee('Verificar agora com as 2 contas')
        ->assertSee('Rotina diária')
        ->assertSee('Últimos números verificados')
        ->assertDontSee('Monitoramento das verificações');
});
