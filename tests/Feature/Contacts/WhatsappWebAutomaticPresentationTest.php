<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/** Cria apenas dados sinteticos de teste; nao consulta o WhatsApp real. */
function waAutomaticDisplayPhoneV1(string $digits, string $result, string $status = 'unknown', ?string $evidence = null, ?int $verifiedBy = null): int
{
    $now = now('UTC');
    $id = (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'br:'.$digits,
        'digits' => $digits,
        'e164' => $digits,
        'kind' => 'mobile',
        'status' => $status,
        'evidence' => $evidence,
        'verified_by' => $verifiedBy,
        'contacts_count' => 1,
        'occurrences_count' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    DB::table('whatsapp_web_probe_results')->insert([
        'phone_id' => $id,
        'result' => $result,
        'provider' => 'baileys_unofficial',
        'session' => 1,
        'checked_at' => $now,
        'attempts' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function waAutomaticDisplayManagerV1(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.enabled', false);
    config()->set('campaign_mail.live_enabled', false);
});

it('mostra o positivo do robo como Tem WhatsApp sem exigir outra confirmacao manual', function (): void {
    $digits = '5545999915001';
    $id = waAutomaticDisplayPhoneV1($digits, 'found', 'yes', 'web_found_unofficial');

    $this->actingAs(waAutomaticDisplayManagerV1())
        ->get(route('contacts.whatsapp', ['busca' => $digits]))
        ->assertOk()
        ->assertSee('Tem WhatsApp · robô')
        ->assertSee('Resultado do robô já disponível')
        ->assertSee('Corrigir resultado manualmente (opcional)')
        ->assertDontSee('Indício técnico — conferir');

    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('yes')
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('evidence'))->toBe('web_found_unofficial')
        ->and(DB::table('contact_phone_verifications')->count())->toBe(0);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('apresenta not_found como resultado negativo do robo sem inventar confirmacao humana', function (): void {
    $digits = '5545999915002';
    $id = waAutomaticDisplayPhoneV1($digits, 'not_found');

    $this->actingAs(waAutomaticDisplayManagerV1())
        ->get(route('contacts.whatsapp', ['busca' => $digits]))
        ->assertOk()
        ->assertSee('Não possui WhatsApp · robô')
        ->assertSee('ausência não foi confirmada manualmente')
        ->assertSee('Corrigir resultado manualmente (opcional)')
        ->assertDontSee('Tem WhatsApp · robô');

    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('unknown')
        ->and(DB::table('contact_phone_verifications')->count())->toBe(0);
    Mail::assertNothingSent();
});

it('nao transforma consulta inconclusiva ou erro em nao possui WhatsApp', function (): void {
    foreach (['inconclusive' => '5545999915003', 'error' => '5545999915004'] as $result => $digits) {
        $id = waAutomaticDisplayPhoneV1($digits, $result);
        $this->actingAs(waAutomaticDisplayManagerV1())
            ->get(route('contacts.whatsapp', ['busca' => $digits]))
            ->assertOk()
            ->assertSee($result === 'inconclusive' ? 'Consulta inconclusiva' : 'Falha na consulta')
            ->assertDontSee('Não possui WhatsApp · robô');
        expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('unknown');
    }
    Mail::assertNothingSent();
});

it('mantem a confirmacao manual acima de resultado posterior conflitante', function (): void {
    $manager = waAutomaticDisplayManagerV1();
    $digits = '5545999915005';
    waAutomaticDisplayPhoneV1($digits, 'not_found', 'yes', 'manual_app_confirmed', (int) $manager->id);

    $this->actingAs($manager)
        ->get(route('contacts.whatsapp', ['busca' => $digits]))
        ->assertOk()
        ->assertSee('Com WhatsApp')
        ->assertDontSee('Não possui WhatsApp · robô');
    Mail::assertNothingSent();
});

it('indica divergencia quando um positivo tecnico antigo recebe um not_found mais recente', function (): void {
    $digits = '5545999915006';
    $id = waAutomaticDisplayPhoneV1($digits, 'not_found', 'yes', 'web_found_unofficial');

    $this->actingAs(waAutomaticDisplayManagerV1())
        ->get(route('contacts.whatsapp', ['busca' => $digits]))
        ->assertOk()
        ->assertSee('Resultados divergentes · revisar')
        ->assertDontSee('Não possui WhatsApp · robô');

    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('yes');
    Mail::assertNothingSent();
});
