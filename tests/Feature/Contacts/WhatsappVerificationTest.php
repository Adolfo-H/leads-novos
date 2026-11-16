<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

function v941Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

function v941Phone(string $phone, ?string $webResult = null): int
{
    $now = now('UTC');
    $id = (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => 'br:'.$phone, 'digits' => $phone, 'e164' => $phone,
        'kind' => 'mobile', 'status' => 'unknown', 'contacts_count' => 1,
        'occurrences_count' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('whatsapp_phone_sources')->insert([
        'phone_id' => $id, 'source_key' => 'hubspot:941000'.$id,
        'origin' => 'hubspot', 'phone_field' => 'mobile_phone',
        'raw_digits' => $phone, 'contact_label' => 'Contato de verificação',
        'scan_id' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
    ]);
    if ($webResult !== null) {
        DB::table('whatsapp_web_probe_results')->insert([
            'phone_id' => $id, 'result' => $webResult, 'provider' => 'baileys_unofficial',
            'checked_at' => $now, 'attempts' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    return $id;
}

it('registra Não possui por POST e sincroniza os contatos com auditoria', function (): void {
    Mail::fake();
    $user = v941Manager();
    $id = v941Phone('5545999912233');
    $key = 'hubspot:941000'.$id;

    $this->actingAs($user)->post(route('contacts.whatsapp.verify-v941', ['phone' => $id]), [
        'status' => 'no', 'confirmed' => '1', 'note' => 'Conferido manualmente no aplicativo.',
    ])->assertRedirect(route('contacts.whatsapp', ['busca' => '5545999912233']));

    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('no');
    expect(DB::table('contact_phone_verifications')->where('source_key', $key)
        ->value('whatsapp_status'))->toBe('no');
    expect(DB::table('whatsapp_phone_events')->where('phone_id', $id)->count())->toBe(1);
    Mail::assertNothingSent();
});

it('não inventa confirmação manual quando o gestor não confirma', function (): void {
    $id = v941Phone('5545999912244');
    $this->actingAs(v941Manager())->post(route('contacts.whatsapp.verify-v941', ['phone' => $id]), [
        'status' => 'no',
    ])->assertSessionHasErrors('confirmed');
    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('unknown');
});

it('nao grava ausencia sem observacao real nem desfaz sem confirmacao', function (): void {
    $user = v941Manager();
    $id = v941Phone('5545999912245');

    $this->actingAs($user)->post(route('contacts.whatsapp.verify-v941', ['phone' => $id]), [
        'status' => 'no', 'confirmed' => '1',
    ])->assertSessionHasErrors('note');
    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('unknown');
    expect(DB::table('whatsapp_phone_events')->where('phone_id', $id)->count())->toBe(0);

    $this->post(route('contacts.whatsapp.verify-v941', ['phone' => $id]), [
        'status' => 'yes', 'confirmed' => '1',
    ])->assertRedirect();
    $this->post(route('contacts.whatsapp.verify-v941', ['phone' => $id]), [
        'status' => 'unknown',
    ])->assertSessionHasErrors('confirmed');
    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('yes');
    expect(DB::table('whatsapp_phone_events')->where('phone_id', $id)->count())->toBe(1);
});

it('consulta inconclusiva permanece sem confirmação factual e não é marcada como ausência', function (): void {
    $id = v941Phone('5545999912255', 'inconclusive');
    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('unknown');
    $this->actingAs(v941Manager())->get(route('contacts.whatsapp', ['busca' => '5545999912255']))
        ->assertOk()->assertSee('Não confirmado — não utilizar WhatsApp');
});

it('bloqueia POST anônimo para a verificação manual', function (): void {
    $id = v941Phone('5545999912266');
    $this->post(route('contacts.whatsapp.verify-v941', ['phone' => $id]), [
        'status' => 'no', 'confirmed' => '1',
    ])->assertRedirect(route('login'));
    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('unknown');
});
