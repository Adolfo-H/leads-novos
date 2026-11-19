<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function waHttpOperationManager(bool $manager = true): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => $manager ? User::ROLE_MANAGER : User::ROLE_SELLER])->save();

    return $user;
}

function waHttpOperationPhone(string $digits, string $kind = 'mobile'): int
{
    return (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'br:'.$digits,
        'digits' => $digits,
        'e164' => $kind === 'review' ? null : $digits,
        'kind' => $kind,
        'status' => 'unknown',
        'contacts_count' => 1,
        'occurrences_count' => 1,
        'created_at' => now('UTC'),
        'updated_at' => now('UTC'),
    ]);
}

beforeEach(function (): void {
    Mail::fake();
    Queue::fake();
    config()->set('campaign_mail.live_enabled', false);
    config()->set('campaign_mail.enabled', false);
});

it('mostra todos os controles HTTP de consulta e revisao no painel do gestor', function (): void {
    $this->actingAs(waHttpOperationManager())
        ->get(route('contacts.whatsapp', ['aba' => 'operation']))
        ->assertOk()
        ->assertSee('wa-http-operations-form-v1')
        ->assertSee('Consultar números informados')
        ->assertSee('Buscar número')
        ->assertSee('Ver formatos a revisar')
        ->assertSee('Filtrar lista pelos consultados');

    $markup = file_get_contents(resource_path('views/contacts/whatsapp-operation.blade.php'));
    expect((string) $markup)->toContain('name="mode" value="both"')
        ->toContain('name="mode" value="single:{{ $agent->session }}"')
        ->toContain("route('contacts.whatsapp.operations-http-v1')");
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('bloqueia visitante vendedor e requisicoes invalidas sem criar lotes', function (): void {
    $url = route('contacts.whatsapp.operations-http-v1');
    $this->post($url, ['mode' => 'both', 'scope' => 'all', 'limit' => '3'])->assertRedirect();
    $this->actingAs(waHttpOperationManager(false))->post($url, [
        'mode' => 'both', 'scope' => 'all', 'limit' => '3',
    ])->assertForbidden();
    $this->actingAs(waHttpOperationManager())->post($url, [
        'mode' => 'both', 'scope' => 'all', 'limit' => '99',
    ])->assertSessionHasErrors('limit');
    expect(DB::table('whatsapp_web_scan_batches')->count())->toBe(0);
});

it('solicita lotes nas duas contas conectadas sem executar o robo no teste', function (): void {
    $user = waHttpOperationManager();
    DB::table('whatsapp_web_scan_settings')->where('id', 1)->update([
        'daily_limit' => 200, 'auto_enabled' => false,
    ]);
    DB::table('whatsapp_web_scan_agents')->whereIn('session', [1, 2])->update([
        'connection_state' => 'connected', 'last_heartbeat_at' => now('UTC'),
    ]);
    foreach (['5545999991001', '5545999991002', '5545999991003', '5545999991004'] as $phone) {
        waHttpOperationPhone($phone);
    }
    $this->actingAs($user)->post(route('contacts.whatsapp.operations-http-v1'), [
        'mode' => 'both', 'scope' => 'mobile', 'limit' => 3,
    ])->assertRedirect()->assertSessionHas('wa_operations_success');
    expect(DB::table('whatsapp_web_scan_batches')->where('status', 'queued')->count())->toBe(2);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('insere somente numeros digitados por POST explicito, sem mensagem WhatsApp', function (): void {
    $user = waHttpOperationManager();
    DB::table('whatsapp_web_scan_settings')->where('id', 1)->update(['daily_limit' => 200]);
    DB::table('whatsapp_web_scan_agents')->whereIn('session', [1, 2])->update([
        'connection_state' => 'connected', 'last_heartbeat_at' => now('UTC'),
    ]);
    $this->actingAs($user)->post(route('contacts.whatsapp.operations-http-v1'), [
        'mode' => 'specific', 'numbers' => "+55 (45) 99999-1111\n+55 (11) 98888-7777",
    ])->assertRedirect()->assertSessionHas('wa_operations_success');
    expect(DB::table('whatsapp_web_scan_batches')->whereNotNull('target_phone_ids')->sum('requested_limit'))->toBe(2);
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('abre o numero a revisar por GET e so grava decisao com confirmacao explicita', function (): void {
    $user = waHttpOperationManager();
    $id = waHttpOperationPhone('459999110', 'review');
    $this->actingAs($user)->get(route('contacts.whatsapp', [
        'aba' => 'operation', 'revisarBusca' => '459', 'revisarNumero' => $id,
    ]))->assertOk()->assertSee('Salvar correção')->assertSee('Registrar como inválido');

    $url = route('contacts.whatsapp.operations-http-v1');
    $this->actingAs($user)->post($url, [
        'mode' => 'review:invalid', 'phone_id' => $id,
    ])->assertSessionHasErrors('review_confirm');
    expect(DB::table('whatsapp_phone_format_reviews_v16')->count())->toBe(0);

    $this->actingAs($user)->post($url, [
        'mode' => 'review:invalid', 'phone_id' => $id, 'review_confirm' => '1',
    ])->assertRedirect()->assertSessionHas('wa_operations_success');
    expect(DB::table('whatsapp_phone_format_reviews_v16')
        ->where('original_phone_id', $id)->value('decision'))->toBe('invalid');
    $this->actingAs($user)->post($url, [
        'mode' => 'review:corrected', 'phone_id' => $id,
        'corrected_phone' => '+55 45 99999-1111', 'review_confirm' => '1',
    ])->assertSessionHasErrors('wa16_format');
    expect(DB::table('whatsapp_phone_format_reviews_v16')->count())->toBe(1);
});

it('permite consultar por uma conta e cancelar lote por POST sem Livewire', function (): void {
    $user = waHttpOperationManager();
    DB::table('whatsapp_web_scan_settings')->where('id', 1)->update(['daily_limit' => 200]);
    DB::table('whatsapp_web_scan_agents')->whereIn('session', [1, 2])->update([
        'connection_state' => 'connected', 'last_heartbeat_at' => now('UTC'),
    ]);
    $a = waHttpOperationPhone('5545999993001');
    $b = waHttpOperationPhone('5545999993002');
    $session = $a % 2 === 0 ? 1 : 2;
    expect($b % 2)->not->toBe($a % 2);
    $this->actingAs($user)->post(route('contacts.whatsapp.operations-http-v1'), [
        'mode' => 'single:'.$session, 'scope' => 'mobile', 'limit' => '3',
    ])->assertSessionHas('wa_operations_success');
    $batch = DB::table('whatsapp_web_scan_batches')->where('session', $session)->first();
    expect($batch)->not->toBeNull()->and($batch->status)->toBe('queued');
    $this->actingAs($user)->post(route('contacts.whatsapp.operations-http-v1'), [
        'mode' => 'cancel', 'batch_id' => $batch->id,
    ])->assertSessionHas('wa_operations_success');
    expect(DB::table('whatsapp_web_scan_batches')->where('id', $batch->id)->value('status'))->toBe('cancelled');
});
