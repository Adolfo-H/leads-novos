<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Testa a partição usando IDs reais, sem rede e sem depender da paridade inicial do SQLite. */
function waDualV5Number(string $phone): int
{
    return (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'br:'.$phone,
        'digits' => $phone,
        'e164' => $phone,
        'kind' => 'mobile',
        'status' => 'unknown',
        'contacts_count' => 2,
        'occurrences_count' => 2,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
}

/** @return list<int> */
function waDualV5Candidates(array $options = []): array
{
    expect(Artisan::call('contacts:wa-web:candidates', array_merge(['--limit' => '10'], $options)))->toBe(0);
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    return array_map('intval', array_column($payload['candidates'], 'id'));
}

it('distributes every eligible phone into exactly one of the two account queues', function () {
    $ids = [];
    for ($i = 0; $i < 8; $i++) {
        $ids[] = waDualV5Number('554599991'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
    }
    $first = waDualV5Candidates(['--slot' => '0']);
    $second = waDualV5Candidates(['--slot' => '1']);
    $all = waDualV5Candidates(); // Compatibilidade com V4: sem partição = tudo.
    expect(array_intersect($first, $second))->toBe([]);
    sort($ids);
    $both = array_merge($first, $second);
    sort($both);
    sort($all);
    expect($both)->toBe($ids)->and($all)->toBe($ids);
    foreach ($first as $id) {
        expect($id % 2)->toBe(0);
    }
    foreach ($second as $id) {
        expect($id % 2)->toBe(1);
    }
});

it('still respects existing 30-day probe cooldown across both accounts', function () {
    $id = waDualV5Number('5545999977777');
    $slot = (string) ($id % 2);
    expect(waDualV5Candidates(['--slot' => $slot]))->toContain($id);
    expect(Artisan::call('contacts:wa-web:record', [
        'phone_id' => $id, 'result' => 'found',
    ]))->toBe(0);
    expect(waDualV5Candidates(['--slot' => '0']))->not->toContain($id);
    expect(waDualV5Candidates(['--slot' => '1']))->not->toContain($id);
    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('yes'); // EC_WA_V7_EXPECTATIONS
});

it('rejects invalid partitions and leaves the V4 default usable', function () {
    waDualV5Number('5545999966666');
    expect(Artisan::call('contacts:wa-web:candidates', ['--limit' => '5', '--slot' => '2']))->toBe(1);
    expect(waDualV5Candidates())->toHaveCount(1);
});
