<?php

use App\Services\Contacts\WhatsappFullFormatSweepV21;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function wa21InsertReview(string $digits, int $links = 3): int
{
    $now = now('UTC');

    return (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'raw:'.hash('sha256', $digits),
        'digits' => $digits,
        'e164' => null,
        'kind' => 'review',
        'status' => 'unknown',
        'contacts_count' => $links,
        'occurrences_count' => $links,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function wa21InsertDestination(string $e164, string $status = 'unknown'): int
{
    return (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => $e164, 'digits' => $e164, 'e164' => $e164,
        'kind' => 'mobile', 'status' => $status, 'contacts_count' => 1,
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
}

it('identifies old mobile format with DDD, country code or known call prefixes', function (): void {
    $f = WhatsappFullFormatSweepV21::class;
    expect($f::candidate('41 9236-9949'))->toBe('5541992369949')
        ->and($f::candidate('55 41 9236-9949'))->toBe('5541992369949')
        ->and($f::candidate('0 41 9236-9949'))->toBe('5541992369949')
        ->and($f::candidate('00 55 41 9236-9949'))->toBe('5541992369949')
        ->and($f::candidate('0 21 41 9236-9949'))->toBe('5541992369949');
});

it('never invents DDD or inserts ninth digit into landlines or invalid inputs', function (): void {
    $f = WhatsappFullFormatSweepV21::class;
    expect($f::candidate('92369949'))->toBeNull()
        ->and($f::candidate('41 3234-5678'))->toBeNull()
        ->and($f::candidate('00 9236-9949'))->toBeNull()
        ->and($f::candidate('0000000000'))->toBeNull()
        ->and($f::candidate('55 41 9236-9949 41990000000'))->toBeNull();
});

it('checks ALL review records and records idempotent, auditable local conversions', function (): void {
    $id = wa21InsertReview('04192369949', 140);
    wa21InsertReview('92369949', 12);
    $service = app(WhatsappFullFormatSweepV21::class);
    $preview = $service->execute();
    expect($preview['reviewed'])->toBe(2)
        ->and($preview['candidates'])->toBe(1)
        ->and($preview['no_ddd'])->toBe(1)
        ->and($preview['eligible'])->toBe(1)
        ->and(DB::table('whatsapp_phone_auto_corrections_v18')->count())->toBe(0);

    $applied = $service->execute(true);
    expect($applied['applied'])->toBe(1)
        ->and(DB::table('whatsapp_phone_auto_corrections_v18')->where('original_phone_id', $id)
            ->value('corrected_e164'))->toBe('5541992369949')
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('digits'))->toBe('04192369949')
        ->and($service->execute(true)['applied'])->toBe(0);
});

it('only merges into an already verified destination with the explicit flag', function (): void {
    $old = wa21InsertReview('1191131515', 14);
    $target = wa21InsertDestination('5511991131515', 'yes');
    // Uma consulta anterior no destino não pode ser apagada pela conciliação.
    DB::table('whatsapp_web_probe_results')->insert([
        'phone_id' => $target, 'result' => 'found',
        'checked_at' => now('UTC'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
    $service = app(WhatsappFullFormatSweepV21::class);
    expect($service->execute()['eligible'])->toBe(0)
        ->and($service->execute()['blocked_destination'])->toBe(1)
        ->and($service->execute(false, true)['eligible'])->toBe(1)
        ->and($service->execute(false, true)['eligible_existing_verified'])->toBe(1)
        ->and(DB::table('whatsapp_phone_auto_corrections_v18')->count())->toBe(0);

    expect($service->execute(true, true)['applied'])->toBe(1)
        ->and(DB::table('whatsapp_phone_auto_corrections_v18')
            ->where('original_phone_id', $old)->value('corrected_e164'))->toBe('5511991131515')
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $target)->value('status'))->toBe('yes');
});

it('does not merge a previous verification history that was later reset to unknown', function (): void {
    wa21InsertReview('1191131515');
    $target = wa21InsertDestination('5511991131515');
    DB::table('whatsapp_phone_events')->insert([
        'phone_id' => $target, 'old_status' => 'unknown',
        'new_status' => 'no', 'evidence' => 'manual_app', 'created_at' => now('UTC'),
    ]);
    $result = app(WhatsappFullFormatSweepV21::class)->execute(true, true);
    expect($result['eligible'])->toBe(0)
        ->and($result['blocked_history'])->toBe(1)
        ->and($result['applied'])->toBe(0);
});

it('previews the console command without writing data', function (): void {
    wa21InsertReview('4192369949');
    expect(Artisan::call('contacts:wa-format:full-sweep', [
        '--include-verified-destinations' => true,
    ]))->toBe(0)
        ->and(DB::table('whatsapp_phone_auto_corrections_v18')->count())->toBe(0);
});
