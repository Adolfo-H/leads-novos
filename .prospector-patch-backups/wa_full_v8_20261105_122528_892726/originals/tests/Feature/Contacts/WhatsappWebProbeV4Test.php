<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function waUnofficialPhone(string $phone = '5545999991010', string $kind = 'mobile', string $status = 'unknown'): int
{
    return (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'br:'.$phone,
        'digits' => $phone,
        'e164' => $phone,
        'kind' => $kind,
        'status' => $status,
        'contacts_count' => 2,
        'occurrences_count' => 2,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
}

it('lists only well-formed unconfirmed mobile candidates and caps the batch size', function () {
    $pending = waUnofficialPhone();
    waUnofficialPhone('554532345555', 'landline');
    waUnofficialPhone('5545999993030', 'mobile', 'yes');

    expect(Artisan::call('contacts:wa-web:candidates', ['--limit' => '5']))->toBe(0);
    $data = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    expect($data['count'])->toBe(1)
        ->and($data['candidates'][0]['id'])->toBe($pending);

    expect(Artisan::call('contacts:wa-web:candidates', ['--limit' => '50']))->toBe(1);
});

// EC_WA_V7_EXPECTATIONS: encontrados promovidos, ausência continua sem confirmação.
it('records a web positive as technical confirmation without overwriting humans', function () {
    $id = waUnofficialPhone();
    expect(Artisan::call('contacts:wa-web:record', [
        'phone_id' => $id, 'result' => 'found',
    ]))->toBe(0);

    $found = DB::table('whatsapp_web_probe_results')->where('phone_id', $id)->first();
    expect($found)->not->toBeNull()
        ->and($found->result)->toBe('found')
        ->and($found->attempts)->toBe(1)
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('yes')
        ->and(DB::table('contact_phone_verifications')->count())->toBe(0);

    // Repetir não duplica registro; mantém trilha de observações.
    expect(Artisan::call('contacts:wa-web:record', [
        'phone_id' => $id, 'result' => 'not_found',
    ]))->toBe(0);
    expect(DB::table('whatsapp_web_probe_results')->where('phone_id', $id)->count())->toBe(1)
        ->and(DB::table('whatsapp_web_probe_events')->where('phone_id', $id)->count())->toBe(2)
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('yes');
});

it('never interprets an experimental negative as a confirmed absence', function () {
    $id = waUnofficialPhone('5545999994040');
    Artisan::call('contacts:wa-web:record', ['phone_id' => $id, 'result' => 'not_found']);
    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('unknown');
    expect(Artisan::call('contacts:wa-web:candidates', ['--limit' => '5']))->toBe(0);
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
    expect($payload['count'])->toBe(0); // Não reconsulta imediatamente o mesmo número.
});

it('retains a manual positive even if a web probe later fails', function () {
    $id = waUnofficialPhone('5545999995050', 'mobile', 'yes');
    Artisan::call('contacts:wa-web:record', ['phone_id' => $id, 'result' => 'error']);
    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('yes');
});

it('renders the web observation separately for the authenticated manager', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    $id = waUnofficialPhone();
    Artisan::call('contacts:wa-web:record', ['phone_id' => $id, 'result' => 'found']);
    $this->actingAs($user)
        ->get(route('contacts.whatsapp'))
        ->assertOk()
        ->assertSee('Acompanhe tudo em um só lugar')
        ->assertSee('WhatsApp identificado pela integração Web');
});
