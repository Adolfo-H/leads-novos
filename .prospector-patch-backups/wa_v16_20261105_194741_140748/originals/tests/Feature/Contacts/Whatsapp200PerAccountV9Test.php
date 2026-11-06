<?php

use App\Models\User;
use App\Services\Contacts\WhatsappWebControlPanel;
use App\Services\Contacts\WhatsappWebDailyCoordinator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function wa9Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function wa9Phone(string $digits): int
{
    return (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'br:'.$digits,
        'digits' => $digits, 'e164' => $digits, 'kind' => 'mobile',
        'status' => 'unknown', 'contacts_count' => 1, 'occurrences_count' => 1,
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
}

it('allows 200 total and stores both start and end times', function () {
    $actor = wa9Manager();
    $planner = app(WhatsappWebDailyCoordinator::class);
    $planner->save($actor, 200, '08:00', true, 'all', '18:00');
    $saved = $planner->settings();

    expect((int) $saved->daily_limit)->toBe(200)
        ->and((bool) $saved->auto_enabled)->toBeTrue()
        ->and((string) $saved->end_time)->toBe('18:00')
        ->and($planner->budget($saved)['per_account_limit'])->toBe(100);

    expect(fn () => $planner->save($actor, 201, '08:00', true, 'all', '18:00'))
        ->toThrow(ValidationException::class);
    expect(fn () => $planner->save($actor, 200, '18:00', true, 'all', '08:00'))
        ->toThrow(ValidationException::class);
});

it('does not lend the unused quota from account 2 to account 1', function () {
    $actor = wa9Manager();
    app(WhatsappWebDailyCoordinator::class)->save($actor, 200, '08:00', false, 'all', '18:00');
    $phone = wa9Phone('5545999991081');
    DB::table('whatsapp_web_scan_agents')->where('session', 1)->update([
        'connection_state' => 'connected', 'last_heartbeat_at' => now('UTC'),
    ]);
    $events = [];
    for ($i = 0; $i < 100; $i++) {
        $events[] = [
            'phone_id' => $phone, 'session' => 1, 'result' => 'found',
            'provider' => 'baileys_unofficial', 'checked_at' => now('UTC'),
        ];
    }
    DB::table('whatsapp_web_probe_events')->insert($events);
    $budget = app(WhatsappWebDailyCoordinator::class)->budget();
    expect($budget['sessions'][1]['checked'])->toBe(100)
        ->and($budget['sessions'][1]['remaining'])->toBe(0)
        ->and($budget['sessions'][2]['remaining'])->toBe(100)
        ->and($budget['remaining'])->toBe(100);

    expect(fn () => app(WhatsappWebControlPanel::class)->request($actor, 1, 1, 'mobile'))
        ->toThrow(ValidationException::class);
});

it('also enforces the per-account cap on a manual request with queued reservations', function () {
    $actor = wa9Manager();
    app(WhatsappWebDailyCoordinator::class)->save($actor, 200, '08:00', false, 'all', '18:00');
    DB::table('whatsapp_web_scan_agents')->where('session', 2)->update([
        'connection_state' => 'connected', 'last_heartbeat_at' => now('UTC'),
    ]);
    $phone = wa9Phone('5545999991082');
    DB::table('whatsapp_web_scan_batches')->insert([
        'session' => 2, 'requested_by' => $actor->id,
        'requested_limit' => 100, 'processed' => 0, 'status' => 'queued',
        'scan_scope' => 'mobile', 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
    $budget = app(WhatsappWebDailyCoordinator::class)->budget();
    expect($budget['sessions'][2]['reserved'])->toBe(100)
        ->and($budget['sessions'][2]['remaining'])->toBe(0);
    expect(fn () => app(WhatsappWebControlPanel::class)->request($actor, 2, 1))
        ->toThrow(ValidationException::class);
});

it('previews without activation and applies configuration when a manager authorizes', function () {
    $actor = wa9Manager();
    expect(Artisan::call('contacts:wa-web:activate-200', ['--manager-email' => $actor->email]))->toBe(0);
    expect((bool) DB::table('whatsapp_web_scan_settings')->value('auto_enabled'))->toBeFalse();
    expect(Artisan::call('contacts:wa-web:activate-200', [
        '--manager-email' => $actor->email, '--apply' => true,
    ]))->toBe(0);
    $settings = app(WhatsappWebDailyCoordinator::class)->settings();
    expect($settings->auto_enabled)->toBeTruthy()
        ->and((int) $settings->daily_limit)->toBe(200)
        ->and((string) $settings->start_time)->toBe('08:00')
        ->and((string) $settings->end_time)->toBe('18:00');
    expect(Artisan::call('contacts:wa-web:activate-200', ['--disable' => true]))->toBe(0);
    expect((bool) DB::table('whatsapp_web_scan_settings')->value('auto_enabled'))->toBeFalse();
});

it('renders the per-account quota and selectable ending time in the Central', function () {
    $this->actingAs(wa9Manager())->get(route('contacts.whatsapp'))
        ->assertOk()
        ->assertSee('200 números no total')
        ->assertSee('Encerrar às')
        ->assertSee('Por conta:');
});
