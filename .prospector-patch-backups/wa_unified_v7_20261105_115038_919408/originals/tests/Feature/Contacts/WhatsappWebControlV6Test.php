<?php

use App\Models\User;
use App\Services\Contacts\WhatsappPhoneRegistry;
use App\Services\Contacts\WhatsappWebControlPanel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function waControlV6Number(string $number): int
{
    return (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'br:'.$number,
        'digits' => $number, 'e164' => $number, 'kind' => 'mobile',
        'status' => 'unknown', 'contacts_count' => 2, 'occurrences_count' => 2,
        'created_at' => now('UTC'), 'updated_at' => now('UTC'),
    ]);
}

function waControlV6User(bool $manager = true): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => $manager ? User::ROLE_MANAGER : User::ROLE_SELLER])->save();
    return $user;
}

function waControlV6Agent(string $action, int $session, array $extra = []): array
{
    $code = Artisan::call('contacts:wa-web:agent', array_merge([
        'action' => $action, '--session' => (string) $session,
    ], $extra));
    expect($code)->toBe(0);
    return json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
}

it('shows true consultation progress separately from the unchanged number of registered mobiles', function () {
    $id1 = waControlV6Number('5545999971111');
    waControlV6Number('5545999972222');
    expect(Artisan::call('contacts:wa-web:record', [
        'phone_id' => $id1, 'result' => 'found', '--session' => '1',
    ]))->toBe(0);

    $metrics = app(WhatsappWebControlPanel::class)->metrics();
    expect($metrics['total'])->toBe(2)
        ->and($metrics['consulted'])->toBe(1)
        ->and($metrics['never_consulted'])->toBe(1)
        ->and($metrics['found'])->toBe(1);
    $summary = app(WhatsappPhoneRegistry::class)->totals();
    expect($summary['mobile'])->toBe(2);
    expect($summary['yes'])->toBe(0);
    expect(app(WhatsappWebControlPanel::class)->latest()->first()->session)->toBe(1);

    $this->actingAs(waControlV6User())->get(route('contacts.whatsapp'))
        ->assertOk()->assertSee('Monitoramento das verificações')
        ->assertSee('Últimos números consultados')->assertSee('Já consultados')
        ->assertSee('Não consultados')->assertSee('Encontrado');
});

it('filters technically consulted numbers before pagination without modifying human statuses', function () {
    $found = waControlV6Number('5545999973333');
    $never = waControlV6Number('5545999974444');
    Artisan::call('contacts:wa-web:record', ['phone_id' => $found, 'result' => 'found']);

    $registry = app(WhatsappPhoneRegistry::class);
    $foundRows = $registry->search('', 'mobile', 'all', 'all', 1, 'found');
    $neverRows = $registry->search('', 'mobile', 'unknown', 'all', 1, 'never');
    expect($foundRows->total())->toBe(1)
        ->and((int) $foundRows->items()[0]->id)->toBe($found)
        ->and($neverRows->total())->toBe(1)
        ->and((int) $neverRows->items()[0]->id)->toBe($never);
});

it('requires a connected, recent agent and manager to request a small controlled batch', function () {
    $user = waControlV6User();
    $seller = waControlV6User(false);
    waControlV6Number('5545999975555');
    waControlV6Number('5545999976666'); // Assegura ao menos um candidato em cada partição.
    $panel = app(WhatsappWebControlPanel::class);
    expect(fn () => $panel->request($user, 1, 5))->toThrow(ValidationException::class);
    waControlV6Agent('pulse', 1, ['--state' => 'connected']);
    expect(fn () => $panel->request($seller, 1, 5))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $batch = $panel->request($user, 1, 5);
    expect($batch)->toBeGreaterThan(0);
    expect(DB::table('whatsapp_web_scan_batches')->where('id', $batch)->value('requested_limit'))->toBe(1);
    expect(fn () => $panel->request($user, 1, 5))->toThrow(ValidationException::class);

    $claimed = waControlV6Agent('claim', 1);
    expect((int) $claimed['id'])->toBe($batch);
    expect(waControlV6Agent('continue', 1, ['--batch' => (string) $batch])['continue'])->toBeTrue();
    waControlV6Agent('progress', 1, ['--batch' => (string) $batch, '--result' => 'found']);
    waControlV6Agent('finish', 1, ['--batch' => (string) $batch]);
    $row = DB::table('whatsapp_web_scan_batches')->where('id', $batch)->first();
    expect($row->status)->toBe('completed')
        ->and($row->processed)->toBe(1)
        ->and($row->found)->toBe(1);
});

it('can cancel the remaining checks without modifying phone records', function () {
    $user = waControlV6User();
    // Garantir candidato de slot 2 independentemente dos IDs iniciais.
    for ($i = 0; $i < 4; $i++) {
        waControlV6Number('554599998'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
    }
    waControlV6Agent('pulse', 2, ['--state' => 'connected']);
    $batch = app(WhatsappWebControlPanel::class)->request($user, 2, 3);
    expect(waControlV6Agent('claim', 2)['id'])->toBe($batch);
    app(WhatsappWebControlPanel::class)->cancel($user, $batch);
    expect(waControlV6Agent('continue', 2, ['--batch' => (string) $batch])['continue'])->toBeFalse();
    waControlV6Agent('finish', 2, ['--batch' => (string) $batch]);
    expect(DB::table('whatsapp_web_scan_batches')->where('id', $batch)->value('status'))->toBe('cancelled');
    expect(DB::table('whatsapp_phone_numbers')->where('status', 'unknown')->count())->toBe(4);
});
