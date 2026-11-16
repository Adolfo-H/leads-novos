<?php

use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Contacts\ContactPeopleDirectory;
use App\Services\Contacts\WhatsappPhoneRegistry;
use App\Services\Contacts\WhatsappWebDailyCoordinator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        'scan_id' => (string) Str::uuid(),
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
}

it('keeps a web positive as a technical registry status without making a manual contact confirmation', function () {
    $id = wa7Phone('5545999991011');
    wa7Source($id, 'hubspot:contacto-teste-v7');
    expect(Artisan::call('contacts:wa-web:record', [
        'phone_id' => $id, 'result' => 'found', '--session' => '1',
    ]))->toBe(0);
    $phone = DB::table('whatsapp_phone_numbers')->where('id', $id)->first();
    expect($phone->status)->toBe('yes')
        ->and($phone->evidence)->toBe('web_found_unofficial')
        ->and($phone->verified_by)->toBeNull()
        ->and(DB::table('whatsapp_web_probe_results')->where('phone_id', $id)->value('result'))->toBe('found')
        ->and(DB::table('contact_phone_verifications')->where('source_key', 'hubspot:contacto-teste-v7')->exists())->toBeFalse()
        ->and(DB::table('whatsapp_phone_events')->where('phone_id', $id)->where('evidence', 'web_found_unofficial')->count())->toBe(1);
});

it('does not override human negatives or auto-label a not-found result as absence', function () {
    $manual = wa7Phone('5545999992022', 'no');
    $negative = wa7Phone('5545999993033');
    $directoryNo = wa7Phone('5545999994045');
    wa7Source($directoryNo, 'hubspot:manual-no-v7');
    DB::table('contact_phone_verifications')->insert([
        'source_key' => 'hubspot:manual-no-v7', 'phone_normalized' => '5545999994045',
        'whatsapp_status' => 'no', 'verified_by' => null,
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
    expect(Artisan::call('contacts:wa-web:record', [
        'phone_id' => $manual, 'result' => 'found',
    ]))->toBe(0);
    expect(Artisan::call('contacts:wa-web:record', [
        'phone_id' => $negative, 'result' => 'not_found',
    ]))->toBe(0);
    expect(DB::table('whatsapp_phone_numbers')->where('id', $manual)->value('status'))->toBe('no')
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $negative)->value('status'))->toBe('unknown');
    expect(Artisan::call('contacts:wa-web:record', [
        'phone_id' => $directoryNo, 'result' => 'found',
    ]))->toBe(0);
    expect(DB::table('whatsapp_phone_numbers')->where('id', $directoryNo)->value('status'))->toBe('unknown')
        ->and(DB::table('contact_phone_verifications')->where('source_key', 'hubspot:manual-no-v7')
            ->value('whatsapp_status'))->toBe('no');
});

it('keeps historic positive reconciliation read-only, including with the legacy apply option', function () {
    $id = wa7Phone('5545999994044');
    DB::table('whatsapp_web_probe_results')->insert([
        'phone_id' => $id, 'result' => 'found', 'provider' => 'baileys_unofficial',
        'checked_at' => now('UTC'), 'attempts' => 1,
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
    expect(Artisan::call('contacts:wa-web:reconcile-found', ['--apply' => true]))->toBe(0);
    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('unknown')
        ->and(DB::table('whatsapp_phone_events')->where('phone_id', $id)->count())->toBe(0);
    expect(Artisan::call('contacts:wa-web:reconcile-found', ['--apply' => true]))->toBe(0);
    expect(DB::table('whatsapp_phone_events')->where('phone_id', $id)->count())->toBe(0);
});

it('does not inherit or display legacy technical contact rows as manual confirmations', function () {
    $contact = HubSpotContact::query()->create([
        'hubspot_id' => 'wa-technical-legacy-7001',
        'first_name' => 'Contato', 'last_name' => 'Técnico',
        'phone' => '45 99999-7070',
    ]);
    $manualContact = HubSpotContact::query()->create([
        'hubspot_id' => 'wa-technical-legacy-7002',
        'first_name' => 'Contato', 'last_name' => 'Manual',
        'phone' => '45 99999-7070',
    ]);
    $sourceKey = 'hubspot:'.$contact->hubspot_id;
    $manualSourceKey = 'hubspot:'.$manualContact->hubspot_id;
    $technicalAt = now('UTC')->subMinute();
    DB::table('contact_phone_verifications')->insert([
        'source_key' => $sourceKey, 'phone_normalized' => '45999997070',
        'whatsapp_status' => 'yes', 'verified_by' => null,
        'verified_at' => $technicalAt, 'created_at' => $technicalAt, 'updated_at' => $technicalAt,
    ]);

    app(WhatsappPhoneRegistry::class)->sync();
    $phoneId = (int) DB::table('whatsapp_phone_numbers')->where('e164', '5545999997070')->value('id');
    DB::table('contact_phone_verifications')->insert([
        'source_key' => $manualSourceKey, 'phone_normalized' => '45999997070',
        'whatsapp_status' => 'yes', 'verified_by' => null,
        'verified_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
    DB::table('whatsapp_phone_numbers')->where('id', $phoneId)->update([
        'status' => 'yes', 'evidence' => 'web_found_unofficial',
        'verified_by' => null, 'verified_at' => $technicalAt,
    ]);
    DB::table('whatsapp_web_probe_events')->insert([
        'phone_id' => $phoneId, 'result' => 'found', 'provider' => 'baileys_unofficial',
        'checked_at' => $technicalAt,
    ]);

    expect(DB::table('whatsapp_phone_numbers')->where('id', $phoneId)->value('status'))->toBe('yes');
    expect(app(WhatsappPhoneRegistry::class)->totals())
        ->toMatchArray(['yes' => 1, 'pending' => 1]);
    expect(app(WhatsappPhoneRegistry::class)->search('', 'all', 'yes', 'all', 1)->total())->toBe(1);
    expect(app(WhatsappPhoneRegistry::class)->search('', 'all', 'unknown', 'all', 1)->total())
        ->toBe(1);
    $people = app(ContactPeopleDirectory::class);
    $technicalContact = $people->paginate(search: 'Contato Técnico');
    expect($technicalContact->total())->toBe(1)
        ->and($technicalContact->items()[0]->whatsapp_status)->toBe('unknown');
    $directory = $people->paginate();
    $statuses = collect($directory->items())->pluck('whatsapp_status')->sort()->values()->all();
    $leanTotals = $people->totalsLean();
    $aggregatedTotals = $people->totalsAggregated();
    expect($statuses)->toBe(['unknown', 'yes'])
        ->and($leanTotals)->toBe($aggregatedTotals)
        ->and($leanTotals['wa_yes'])->toBe(1)
        ->and($leanTotals['wa_unknown'])->toBe(1);
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
    expect(fn () => $coordinator->save($actor, 201, '09:00', false))->toThrow(ValidationException::class);
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    expect(fn () => $coordinator->save($seller, 10, '08:30', false))
        ->toThrow(HttpException::class);
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
        ->assertSee('Todos os números em uma só central')
        ->assertSee('Verificar agora com as 2 contas')
        ->assertSee('Consulta sob demanda')->assertDontSee('Agendar todos os dias')
        ->assertSee('Histórico recente de consultas')
        ->assertDontSee('Monitoramento das verificações');
});
