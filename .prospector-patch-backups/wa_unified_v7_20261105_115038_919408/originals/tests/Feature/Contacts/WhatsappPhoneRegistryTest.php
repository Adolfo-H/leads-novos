<?php

use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\ContactPhoneVerification;
use App\Models\Establishment;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Contacts\WhatsappPhoneRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function waRegistryManager(): User
{
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $manager;
}

function waRegistryScenario(User $manager): void
{
    $company = Company::query()->create([
        'cnpj_root' => '48170293', 'corporate_name' => 'COOPERATIVA EXEMPLO WA',
    ]);
    HubSpotContact::query()->create([
        'hubspot_id' => '98123001', 'first_name' => 'Teste', 'last_name' => 'Comercial',
        'mobile_phone' => '(45) 99999-1010', 'phone' => '(45) 3234-5555',
        'company_name' => 'COOPERATIVA EXEMPLO WA',
    ]);
    CommercialContact::query()->create([
        'name' => 'Contato manual duplicado', 'mobile_phone' => '+55 45 99999 1010',
        'created_by' => $manager->id,
    ]);
    Establishment::query()->create([
        'company_id' => $company->id, 'cnpj' => '48170293000100',
        'order_number' => '0001', 'check_digits' => '00',
        'type' => 'matrix', 'phone_1' => '(45) 3234-5555', 'email' => 'financeiro@example.test',
    ]);
}

it('indexes distinct numbers from three sources and is idempotent', function () {
    $manager = waRegistryManager();
    waRegistryScenario($manager);
    $service = app(WhatsappPhoneRegistry::class);

    $first = $service->sync();
    $second = $service->sync();

    expect($first['numbers'])->toBe(2)
        ->and($first['mobile'])->toBe(1)
        ->and($first['landline'])->toBe(1)
        ->and($second['numbers'])->toBe(2)
        ->and(DB::table('whatsapp_phone_numbers')->count())->toBe(2)
        ->and(DB::table('whatsapp_phone_sources')->count())->toBe(4);

    expect((int) DB::table('whatsapp_phone_numbers')->where('phone_key', '5545999991010')->value('contacts_count'))->toBe(2);
    expect((int) DB::table('whatsapp_phone_numbers')->where('phone_key', '554532345555')->value('contacts_count'))->toBe(2);
    expect($service->totals()['yes'])->toBe(0);
});

it('records verified statuses only after a human action and propagates to existing contact views', function () {
    $manager = waRegistryManager();
    waRegistryScenario($manager);
    $service = app(WhatsappPhoneRegistry::class);
    $service->sync();
    $id = (int) DB::table('whatsapp_phone_numbers')->where('phone_key', '5545999991010')->value('id');

    $service->record($manager, $id, 'yes', 'Conferi a conta no aplicativo.');
    expect($service->totals()['yes'])->toBe(1)
        ->and(ContactPhoneVerification::query()->where('whatsapp_status', 'yes')->count())->toBe(2)
        ->and(DB::table('whatsapp_phone_events')->count())->toBe(1);

    // Índice pode ser refeito sem destruir evidências.
    $service->sync();
    expect(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('yes');
    $service->record($manager, $id, 'unknown', 'Revisão solicitada.');
    expect($service->totals()['yes'])->toBe(0)
        ->and(ContactPhoneVerification::query()->count())->toBe(0)
        ->and(DB::table('whatsapp_phone_events')->count())->toBe(2);
});

it('does not accept a negative classification without evidence or malformed numbers', function () {
    $manager = waRegistryManager();
    HubSpotContact::query()->create([
        'hubspot_id' => 'wa-bad1', 'first_name' => 'Formato',
        'mobile_phone' => '99999-1010',
    ]);
    app(WhatsappPhoneRegistry::class)->sync();
    $id = (int) DB::table('whatsapp_phone_numbers')->where('kind', 'review')->value('id');
    expect(fn () => app(WhatsappPhoneRegistry::class)->record($manager, $id, 'yes', ''))->toThrow(ValidationException::class);
    expect(DB::table('whatsapp_phone_events')->count())->toBe(0);
});

it('keeps the verification center exclusive to managers', function () {
    $this->get('/contatos/verificacao-whatsapp')->assertRedirect(route('login'));
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->get('/contatos/verificacao-whatsapp')->assertForbidden();
    $this->actingAs(waRegistryManager())->get('/contatos/verificacao-whatsapp')
        ->assertSuccessful()->assertSee('Central de verificação');
});

it('renders contacts and handles confirmation from the actual Livewire page', function () {
    $manager = waRegistryManager();
    waRegistryScenario($manager);
    app(WhatsappPhoneRegistry::class)->sync();

    Livewire::actingAs($manager)
        ->test('pages::contacts.whatsapp')
        ->assertSee('Central de verificação')
        ->assertSee('99999-1010')
        ->call('mark', 'yes')
        ->assertHasNoErrors()
        ->assertSee('Confirmados');

    expect((int) DB::table('whatsapp_phone_numbers')->where('status', 'yes')->count())->toBe(1);
});

it('keeps a manually verified number stable when the origin phone is replaced', function () {
    $manager = waRegistryManager();
    waRegistryScenario($manager);
    $registry = app(WhatsappPhoneRegistry::class);
    $registry->sync();
    $id = (int) DB::table('whatsapp_phone_numbers')->where('phone_key', '5545999991010')->value('id');
    $registry->record($manager, $id, 'yes', 'Conferido no app');

    // Troca de telefone remove apenas o vínculo antigo, não o histórico do número.
    HubSpotContact::query()->where('hubspot_id', '98123001')->update(['mobile_phone' => '(45) 98888-2222']);
    $registry->sync();
    $other = DB::table('whatsapp_phone_numbers')->where('phone_key', '5545988882222')->first();

    expect($other)->not->toBeNull()
        ->and($other->status)->toBe('unknown')
        ->and(DB::table('whatsapp_phone_numbers')->where('id', $id)->value('status'))->toBe('yes')
        ->and(DB::table('whatsapp_phone_sources')->where('phone_id', $id)->count())->toBe(1);
});
