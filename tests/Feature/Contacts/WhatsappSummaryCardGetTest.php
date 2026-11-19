<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function waQuickGetManager(): User
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

/** @return array{yes:int,unknown:int,consulted:int} */
function waQuickGetFixtures(): array
{
    $now = now('UTC');
    $phones = [];
    foreach ([
        ['5545999989201', 'mobile', 'yes', 'manual_app_confirmed'],
        ['5545999989202', 'mobile', 'unknown', null],
        ['554532349203', 'landline', 'unknown', null],
    ] as [$digits, $kind, $status, $evidence]) {
        $phones[] = (int) DB::table('whatsapp_phone_numbers')->insertGetId([
            'phone_key' => 'wa-card-get-'.$digits,
            'digits' => $digits,
            'e164' => $digits,
            'kind' => $kind,
            'status' => $status,
            'evidence' => $evidence,
            'contacts_count' => 1,
            'occurrences_count' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
    DB::table('whatsapp_web_probe_results')->insert([
        'phone_id' => $phones[2],
        'result' => 'inconclusive',
        'provider' => 'baileys_unofficial',
        'checked_at' => $now,
        'attempts' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['yes' => $phones[0], 'unknown' => $phones[1], 'consulted' => $phones[2]];
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.live_enabled', false);
});

it('exibe os quatro indicadores como links GET funcionais com destino a lista', function (): void {
    waQuickGetFixtures();
    $this->actingAs(waQuickGetManager());
    $response = $this->get(route('contacts.whatsapp', ['porPagina' => 20]));
    $response->assertOk()
        ->assertSee('Números cadastrados')
        ->assertSee('Com WhatsApp')
        ->assertSee('A verificar')
        ->assertSee('Consultados via Web');

    $links = [
        route('contacts.whatsapp', [
            'tipo' => 'all', 'situacao' => 'all', 'origem' => 'all',
            'consulta' => 'all', 'publico' => 'all', 'porPagina' => 20,
        ]).'#wa8-lista',
        route('contacts.whatsapp', ['situacao' => 'yes', 'porPagina' => 20]).'#wa8-lista',
        route('contacts.whatsapp', ['situacao' => 'unknown', 'porPagina' => 20]).'#wa8-lista',
        route('contacts.whatsapp', ['consulta' => 'consulted', 'porPagina' => 20]).'#wa8-lista',
    ];
    foreach ($links as $url) {
        $response->assertSee('href="'.e($url).'"', false);
    }
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('aplica de verdade os filtros GET usados pelos quatro indicadores', function (): void {
    waQuickGetFixtures();
    $this->actingAs(waQuickGetManager());

    $this->get(route('contacts.whatsapp', ['situacao' => 'yes', 'porPagina' => 20]))
        ->assertOk()->assertSee('1 números com os filtros atuais');
    $this->get(route('contacts.whatsapp', ['situacao' => 'unknown', 'porPagina' => 20]))
        ->assertOk()->assertSee('2 números com os filtros atuais');
    $this->get(route('contacts.whatsapp', ['consulta' => 'consulted', 'porPagina' => 20]))
        ->assertOk()->assertSee('1 números com os filtros atuais');
    $this->get(route('contacts.whatsapp', ['tipo' => 'all', 'situacao' => 'all',
        'origem' => 'all', 'consulta' => 'all', 'publico' => 'all', 'porPagina' => 20]))
        ->assertOk()->assertSee('3 números com os filtros atuais');

    expect(DB::table('whatsapp_phone_numbers')->where('status', 'yes')->count())->toBe(1)
        ->and(DB::table('whatsapp_phone_numbers')->where('status', 'unknown')->count())->toBe(2);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});
