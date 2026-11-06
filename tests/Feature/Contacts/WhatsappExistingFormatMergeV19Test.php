<?php

use App\Services\Contacts\WhatsappFormatAutoCorrectionV18;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{old:int,target:int} */
function wa19Fixture(int $oldLinks = 14, string $targetStatus = 'unknown'): array
{
    $now = now('UTC');
    $old = (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'raw:wa19-legacy',
        'digits' => '1191131515',
        'e164' => null,
        'kind' => 'review',
        'status' => 'unknown',
        'contacts_count' => $oldLinks,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $target = (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => '5511991131515',
        'digits' => '5511991131515',
        'e164' => '5511991131515',
        'kind' => 'mobile',
        'status' => $targetStatus,
        'contacts_count' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['old' => $old, 'target' => $target];
}

it('requires an explicit flag before reconciling a candidate with an existing unverified number', function (): void {
    $ids = wa19Fixture();
    $service = app(WhatsappFormatAutoCorrectionV18::class);
    expect($service->execute(false, 100)['eligible'])->toBe(0)
        ->and($service->execute(false, 100)['conflict'])->toBe(1)
        ->and($service->execute(false, 100, true)['eligible'])->toBe(1)
        ->and(DB::table('whatsapp_phone_auto_corrections_v18')->count())->toBe(0);

    expect($service->execute(true, 100, true)['applied'])->toBe(1)
        ->and($service->execute(true, 100, true)['applied'])->toBe(0)
        ->and(DB::table('whatsapp_phone_auto_corrections_v18')->where('original_phone_id', $ids['old'])
            ->value('corrected_e164'))->toBe('5511991131515')
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $ids['target'])->value('status'))->toBe('unknown')
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $ids['old'])->value('digits'))->toBe('1191131515');
});

it('never reconciles with a target that already has a verification status', function (): void {
    wa19Fixture(14, 'yes');
    $summary = app(WhatsappFormatAutoCorrectionV18::class)->execute(true, 100, true);
    expect($summary['conflict'])->toBe(1)
        ->and($summary['applied'])->toBe(0);
});

it('never reconciles with a number that already has a WhatsApp technical consultation', function (): void {
    $ids = wa19Fixture();
    DB::table('whatsapp_web_probe_results')->insert([
        'phone_id' => $ids['target'],
        'result' => 'inconclusive',
        'checked_at' => now('UTC'),
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
    $summary = app(WhatsappFormatAutoCorrectionV18::class)->execute(true, 100, true);
    expect($summary['conflict'])->toBe(1)
        ->and($summary['applied'])->toBe(0);
});

it('preserves historical human confirmations even when the current target status is unknown', function (): void {
    $ids = wa19Fixture();
    DB::table('whatsapp_phone_events')->insert([
        'phone_id' => $ids['target'],
        'old_status' => 'unknown',
        'new_status' => 'yes',
        'evidence' => 'manual_app',
        'created_at' => now('UTC'),
    ]);
    $summary = app(WhatsappFormatAutoCorrectionV18::class)->execute(true, 100, true);
    expect($summary['conflict'])->toBe(1)
        ->and($summary['applied'])->toBe(0);
});

it('accepts the preview flag without changing the index', function (): void {
    wa19Fixture();
    expect(Artisan::call('contacts:wa-format:preview', [
        '--max-links' => '100', '--merge-existing-unknown' => true,
    ]))->toBe(0)
        ->and(DB::table('whatsapp_phone_auto_corrections_v18')->count())->toBe(0);
});
