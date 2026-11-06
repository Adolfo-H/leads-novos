<?php

use App\Models\User;
use App\Services\Contacts\WhatsappInlinePhoneCorrectionV20;
use App\Services\Contacts\WhatsappPhoneRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function wa20Actor(): User
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $actor;
}

/** @return array{0:int,1:string} */
function wa20PhoneFixture(string $digits = '1191131515', int $contacts = 3): array
{
    $now = now('UTC');
    $oldKey = 'raw:'.hash('sha256', $digits);
    $id = (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => $oldKey,
        'digits' => $digits,
        'e164' => null,
        'kind' => 'review',
        'status' => 'unknown',
        'contacts_count' => $contacts,
        'occurrences_count' => $contacts,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    for ($i = 1; $i <= $contacts; $i++) {
        DB::table('whatsapp_phone_sources')->insert([
            'phone_id' => $id,
            'source_key' => 'receita:wa20-'.$i,
            'origin' => 'receita',
            'phone_field' => 'phone',
            'raw_digits' => $digits,
            'contact_label' => 'Canal de empresa',
            'company_label' => 'Empresa teste',
            'scan_id' => (string) Str::uuid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return [$id, $oldKey];
}

it('corrects the selected phone in the index and retains every original source', function (): void {
    [$id, $oldKey] = wa20PhoneFixture();
    $count = app(WhatsappInlinePhoneCorrectionV20::class)->correct(
        wa20Actor(), $id, '+55 (11) 99113-1515', 'Conferido no cadastro atualizado',
    );
    $target = DB::table('whatsapp_phone_numbers')->where('e164', '5511991131515')->first();

    expect($count)->toBe(3)
        ->and($target)->not->toBeNull()
        ->and($target->status)->toBe('unknown')
        ->and((int) $target->contacts_count)->toBe(3)
        ->and(DB::table('whatsapp_phone_sources')->where('phone_id', $id)->count())->toBe(0)
        ->and(DB::table('whatsapp_phone_sources')->where('phone_id', $target->id)
            ->where('raw_digits', '1191131515')->count())->toBe(3)
        ->and(DB::table('whatsapp_phone_auto_corrections_v18')
            ->where('original_phone_key', $oldKey)->value('corrected_e164'))->toBe('5511991131515')
        ->and(DB::table('whatsapp_phone_format_reviews_v16')
            ->where('original_phone_id', $id)->value('note'))->toBe('Conferido no cadastro atualizado');
});

it('reuses an unverified canonical destination without erasing its previous references', function (): void {
    [$id] = wa20PhoneFixture(contacts: 2);
    $target = (int) DB::table('whatsapp_phone_numbers')->insertGetId([
        'phone_key' => '5511991131515', 'digits' => '5511991131515',
        'e164' => '5511991131515', 'kind' => 'mobile', 'status' => 'unknown',
        'contacts_count' => 1, 'occurrences_count' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('whatsapp_phone_sources')->insert([
        'phone_id' => $target, 'source_key' => 'manual:existing-target',
        'origin' => 'manual', 'phone_field' => 'phone',
        'raw_digits' => '5511991131515', 'contact_label' => 'Contato antigo',
        'company_label' => 'Empresa existente',
        'scan_id' => (string) Str::uuid(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(app(WhatsappInlinePhoneCorrectionV20::class)
        ->correct(wa20Actor(), $id, '11991131515'))->toBe(2)
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $target)->value('status'))->toBe('unknown')
        ->and(DB::table('whatsapp_phone_sources')->where('phone_id', $target)->count())->toBe(3)
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $target)->value('contacts_count'))->toBe(3);
});

it('does not merge a different phone into an already verified or consulted destination', function (): void {
    [$id] = wa20PhoneFixture();
    DB::table('whatsapp_phone_numbers')->insert([
        'phone_key' => '5511991131515', 'digits' => '5511991131515',
        'e164' => '5511991131515', 'kind' => 'mobile', 'status' => 'yes',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(fn () => app(WhatsappInlinePhoneCorrectionV20::class)
        ->correct(wa20Actor(), $id, '+55 11 99113 1515'))->toThrow(ValidationException::class);
    expect(DB::table('whatsapp_phone_sources')->where('phone_id', $id)->count())->toBe(3);
});

it('lets the manager correct a selected phone directly in the numbers screen', function (): void {
    [$id] = wa20PhoneFixture(contacts: 1);
    Livewire::actingAs(wa20Actor())->test('pages::contacts.whatsapp')
        ->set('kind', 'review')
        ->call('selectNumber', $id)
        ->assertSee('Corrigir este telefone aqui')
        ->set('inlineCorrectedPhone', '+55 11 99113 1515')
        ->set('inlineCorrectionNote', 'Confirmação por telefone com empresa')
        ->call('saveInlinePhoneCorrection')
        ->assertHasNoErrors();
    expect(DB::table('whatsapp_phone_auto_corrections_v18')->where('original_phone_id', $id)->exists())->toBeTrue();
});

it('marks an invalid format as reviewed in the same screen without changing the source', function (): void {
    [$id] = wa20PhoneFixture(contacts: 1);
    Livewire::actingAs(wa20Actor())->test('pages::contacts.whatsapp')
        ->set('kind', 'review')
        ->call('selectNumber', $id)
        ->set('inlineCorrectionNote', 'Numero incompleto confirmado na fonte')
        ->call('markInlinePhoneInvalid')
        ->assertHasNoErrors();

    expect(DB::table('whatsapp_phone_format_reviews_v16')->where('original_phone_id', $id)
        ->value('decision'))->toBe('invalid')
        ->and(app(WhatsappPhoneRegistry::class)->search('', 'review', 'all', 'all', 1)->total())->toBe(0)
        ->and(DB::table('whatsapp_phone_sources')->where('phone_id', $id)->count())->toBe(1);
});

it('prevents sellers from rewriting the index via the service', function (): void {
    [$id] = wa20PhoneFixture();
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    expect(fn () => app(WhatsappInlinePhoneCorrectionV20::class)
        ->correct($seller, $id, '11991131515'))->toThrow(HttpException::class);
});
