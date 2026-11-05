<?php

use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Contacts\ContactWhatsappStatusBridge;
use App\Services\Contacts\WhatsappPhoneRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function waV3Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('deduplicates numbers with leading zero and mirrors a manual decision to every contact', function (): void {
    $manager = waV3Manager();
    HubSpotContact::query()->create([
        'hubspot_id' => '4000001', 'first_name' => 'Pessoa', 'last_name' => 'Um',
        'phone' => '45 99999-1010',
    ]);
    HubSpotContact::query()->create([
        'hubspot_id' => '4000002', 'first_name' => 'Pessoa', 'last_name' => 'Dois',
        'mobile_phone' => '0 45 99999 1010',
    ]);
    $registry = app(WhatsappPhoneRegistry::class);
    $registry->sync();
    $phone = DB::table('whatsapp_phone_numbers')->where('phone_key', '5545999991010')->first();
    expect($phone)->not->toBeNull()
        ->and((int) $phone->contacts_count)->toBe(2)
        ->and(DB::table('whatsapp_phone_numbers')->where('contacts_count', '>', 0)->count())->toBe(1);

    app(ContactWhatsappStatusBridge::class)->change($manager, 'hubspot:4000001', '45999991010', 'phone', 'yes');
    expect(DB::table('whatsapp_phone_numbers')->where('id', $phone->id)->value('status'))->toBe('yes')
        ->and(DB::table('contact_phone_verifications')->where('whatsapp_status', 'yes')->count())->toBe(2)
        ->and(DB::table('whatsapp_phone_events')->count())->toBe(1);

    // Repetir a mesma decisão não deve criar eventos duplicados.
    app(ContactWhatsappStatusBridge::class)->change($manager, 'hubspot:4000001', '45999991010', 'phone', 'yes');
    expect(DB::table('whatsapp_phone_events')->count())->toBe(1);

    $registry->sync();
    expect(DB::table('whatsapp_phone_numbers')->where('id', $phone->id)->value('status'))->toBe('yes');

    $registry->record($manager, (int) $phone->id, 'unknown', 'Retirar conferência');
    expect(DB::table('contact_phone_verifications')->count())->toBe(0);
});

it('reflects a decision from the Contacts Livewire UI immediately in the WhatsApp central index', function (): void {
    $manager = waV3Manager();
    $contact = HubSpotContact::query()->create([
        'hubspot_id' => '4000003', 'first_name' => 'Teste', 'last_name' => 'WhatsApp',
        'mobile_phone' => '(45) 98888-1212',
    ]);
    app(WhatsappPhoneRegistry::class)->sync();

    Livewire::actingAs($manager)->test('pages::contacts.index')
        ->call('setWhatsappFromWorkspace', 'hubspot', $contact->id, 'mobile_phone', 'yes')
        ->assertHasNoErrors();
    expect(DB::table('whatsapp_phone_numbers')->where('phone_key', '5545988881212')->value('status'))->toBe('yes');

    Livewire::actingAs($manager)->test('pages::contacts.index')
        ->call('setWhatsappFromWorkspace', 'hubspot', $contact->id, 'mobile_phone', 'unknown')
        ->assertHasNoErrors();
    expect(DB::table('whatsapp_phone_numbers')->where('phone_key', '5545988881212')->value('status'))->toBe('unknown')
        ->and(DB::table('contact_phone_verifications')->count())->toBe(0);
});

it('keeps the source authoritative and does not mark invalid numbers as WhatsApp confirmed', function (): void {
    $manager = waV3Manager();
    HubSpotContact::query()->create([
        'hubspot_id' => '4000004', 'first_name' => 'Pessoa', 'phone' => '(45) 9999-1010',
    ]);
    app(WhatsappPhoneRegistry::class)->sync();
    expect(fn () => app(ContactWhatsappStatusBridge::class)->change($manager, 'hubspot:4000004', '4599991010', 'phone', 'yes'))
        ->toThrow(ValidationException::class);
    expect(DB::table('contact_phone_verifications')->count())->toBe(0);
    expect(fn () => app(ContactWhatsappStatusBridge::class)->change($manager, 'hubspot:4000004', '4599998888', 'phone', 'unknown'))
        ->toThrow(HttpException::class);
});

it('retains seller access to existing contact confirmations without exposing the manager-only central', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    HubSpotContact::query()->create(['hubspot_id' => '4000005', 'first_name' => 'Outro', 'phone' => '45 3234-5555']);
    app(WhatsappPhoneRegistry::class)->sync();
    app(ContactWhatsappStatusBridge::class)->change($seller, 'hubspot:4000005', '4532345555', 'phone', 'yes');
    expect(DB::table('whatsapp_phone_numbers')->where('phone_key', '554532345555')->value('status'))->toBe('yes');
    $this->actingAs($seller)->get('/contatos/verificacao-whatsapp')->assertForbidden();
});
