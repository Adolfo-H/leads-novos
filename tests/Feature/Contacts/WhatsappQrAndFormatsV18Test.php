<?php

use App\Models\User;
use App\Services\Contacts\BrazilPhoneNumber;
use App\Services\Contacts\WhatsappFormatAutoCorrectionV18;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('only suggests a ninth digit for old mobile-looking numbers with a valid DDD', function (): void {
    expect(BrazilPhoneNumber::legacyMobileCandidate('4192369949'))->toBe('5541992369949')
        ->and(BrazilPhoneNumber::legacyMobileCandidate('554192369949'))->toBe('5541992369949')
        ->and(BrazilPhoneNumber::legacyMobileCandidate('92369949'))->toBeNull()
        ->and(BrazilPhoneNumber::legacyMobileCandidate('001192369949'))->toBeNull()
        ->and(BrazilPhoneNumber::legacyMobileCandidate('1112345678'))->toBeNull()
        ->and(BrazilPhoneNumber::legacyMobileCandidate('0000000000'))->toBeNull();
});

it('previews without modifying the phone index and applies only an explicit local override', function (): void {
    $id = (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'raw:v18-legacy',
        'digits' => '4192369949',
        'kind' => 'review',
        'status' => 'unknown',
        'contacts_count' => 1,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
    $service = app(WhatsappFormatAutoCorrectionV18::class);
    $preview = $service->execute();
    expect($preview['eligible'])->toBe(1)
        ->and(DB::table('whatsapp_phone_auto_corrections_v18')->count())->toBe(0);

    $applied = $service->execute(true);
    expect($applied['applied'])->toBe(1)
        ->and(DB::table('whatsapp_phone_auto_corrections_v18')
            ->where('original_phone_id', $id)->value('corrected_e164'))->toBe('5541992369949')
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('digits'))->toBe('4192369949')
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('unknown')
        ->and($service->execute(true)['applied'])->toBe(0);
});

it('does not automatically merge shared or previously verified numbers', function (): void {
    DB::table('whatsapp_phone_numbers')->insert([
        'phone_key' => 'raw:many-v18', 'digits' => '4192369949', 'kind' => 'review',
        'status' => 'unknown', 'contacts_count' => 52,
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
    expect(app(WhatsappFormatAutoCorrectionV18::class)->execute(false, 5)['shared'])->toBe(1)
        ->and(DB::table('whatsapp_phone_auto_corrections_v18')->count())->toBe(0);
});

it('exposes the local connector heartbeat and retains explicit pairing permission', function (): void {
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    expect(Artisan::call('contacts:wa-web:pairing', [
        'action' => 'heartbeat', '--session' => '1',
    ]))->toBe(0);
    expect(Cache::has('prospector:wa:pair:v13_1:connector:heartbeat'))->toBeTrue();

    Livewire::actingAs($actor)->test('pages::contacts.whatsapp')
        ->assertSee('Conexão por QR Code')
        ->call('requestWebPairing', 1, false)
        ->assertHasNoErrors();
    $request = Cache::get('prospector:wa:pair:v13_1:request:1');
    expect($request)->toBeArray()->toHaveKey('requested_at');
});
