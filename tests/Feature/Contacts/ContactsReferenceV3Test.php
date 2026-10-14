<?php

use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\ContactPhoneVerification;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\Contacts\ContactPeopleDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function contactsReferenceV3Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

/** @return array{company: Company, mirror: HubSpotCompany, person: HubSpotContact} */
function contactsReferenceV3Scenario(): array
{
    $company = Company::query()->create([
        'cnpj_root' => '27441111',
        'corporate_name' => 'AGROINDUSTRIA CONTATOS V3',
    ]);

    $mirror = HubSpotCompany::query()->create([
        'hubspot_id' => '771910',
        'name' => 'AGROINDUSTRIA CONTATOS V3',
        'company_id' => $company->id,
        'match_source' => 'hubspot_explicit_cnpj',
        'lifecycle_stage' => 'customer',
    ]);

    $person = HubSpotContact::query()->create([
        'hubspot_id' => '771911',
        'first_name' => 'Mariana',
        'last_name' => 'Comercial',
        'job_title' => 'Gerente de Exportação',
        'phone' => '(45) 3222-1234',
        'mobile_phone' => '+55 (45) 99999-2222',
        'email' => 'mariana@agroindustria.test',
    ]);

    $mirror->contacts()->attach($person->id, ['is_primary' => true]);

    return compact('company', 'mirror', 'person');
}

it('renders the new reference layout without changing the shared topbar', function (): void {
    $this->actingAs(contactsReferenceV3Manager())
        ->get(route('contacts.index'))
        ->assertSuccessful()
        ->assertSee('data-contacts-design="v3-reference"', false)
        ->assertSee('Central de Contatos')
        ->assertSee('Filtros')
        ->assertSee('Contatos')
        ->assertSee('Detalhes do contato')
        ->assertSee('Novo contato');

    $view = file_get_contents(resource_path('views/pages/contacts/⚡index.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($view)->toContain('ecc3-shell')
        ->toContain('ecc3-table')
        ->toContain('ecc3-inspector')
        ->toContain('Canais cadastrais da Receita')
        ->toContain('WhatsApp não verificado')
        ->and($appCss)->toContain("@import './contacts-reference-v3.css';");
});

it('lists all actual HubSpot and manual people without duplicated mirror associations', function (): void {
    $s = contactsReferenceV3Scenario();
    $anotherMirror = HubSpotCompany::query()->create([
        'hubspot_id' => '771912',
        'name' => 'OUTRA EMPRESA HUBSPOT',
    ]);
    $anotherMirror->contacts()->attach($s['person']->id, ['is_primary' => false]);

    CommercialContact::query()->create([
        'company_id' => $s['company']->id,
        'name' => 'José Manual',
        'email' => 'jose@agroindustria.test',
        'created_by' => contactsReferenceV3Manager()->id,
    ]);

    $people = app(ContactPeopleDirectory::class)->paginate();

    expect($people->total())->toBe(2)
        ->and(collect($people->items())->pluck('person_name')->all())
        ->toContain('Mariana Comercial', 'José Manual');

    Livewire::actingAs(contactsReferenceV3Manager())
        ->test('pages::contacts.index')
        ->assertSee('Mariana Comercial')
        ->assertSee('José Manual')
        ->assertSee('AGROINDUSTRIA CONTATOS V3');
});

it('preserves filters for clients, deals, manual, orphan contacts and confirmed WhatsApp', function (): void {
    $s = contactsReferenceV3Scenario();
    HubSpotContact::query()->create([
        'hubspot_id' => '779999',
        'first_name' => 'Pessoa',
        'last_name' => 'Sem Vínculo',
        'phone' => '(45) 3333-4455',
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => '778881',
        'name' => 'Negócio de exportação real',
    ]);
    $s['person']->deals()->attach($deal->id);
    $directory = app(ContactPeopleDirectory::class);

    expect($directory->paginate('', 'clients')->total())->toBe(1)
        ->and($directory->paginate('', 'deals')->total())->toBe(1)
        ->and($directory->paginate('', 'unlinked')->total())->toBe(1)
        ->and($directory->paginate('', 'manual')->total())->toBe(0)
        ->and($directory->paginate('', 'all', 'hubspot')->total())->toBe(2)
        ->and($directory->paginate('Mariana')->total())->toBe(1)
        ->and($directory->paginate('27441111')->total())->toBe(1);

    $verification = ContactPhoneVerification::query()->create([
        'source_key' => 'hubspot:'.$s['person']->hubspot_id,
        'phone_normalized' => '5545999992222',
        'whatsapp_status' => 'yes',
        'verified_at' => now(),
    ]);

    expect($directory->paginate('', 'wa_yes')->total())->toBe(1)
        ->and($directory->paginate('', 'wa_unknown')->total())->toBe(1)
        ->and(in_array($directory->paginate('', 'all')->items()[0]->whatsapp_status, ['yes', 'unknown'], true))->toBeTrue();

    // Status de telefone antigo nunca transforma o número recém-alterado em confirmado.
    $s['person']->update(['mobile_phone' => '(45) 99999-3333']);
    expect($directory->paginate('', 'wa_yes')->total())->toBe(0)
        ->and($directory->paginate('', 'wa_unknown')->total())->toBe(2);

    $verification->delete();
});

it('does not propagate unsafe HubSpot fiscal matches into the company panel', function (): void {
    $s = contactsReferenceV3Scenario();
    DB::table('hubspot_companies')->where('id', $s['mirror']->id)->update([
        'match_source' => 'hubspot_related_explicit_cnpj',
    ]);

    $directory = app(ContactPeopleDirectory::class);
    $row = $directory->paginate()->items()[0];

    expect($directory->companyContext($row))->toBe('hubspot:'.$s['mirror']->id)
        ->and($directory->paginate('', 'fiscal')->total())->toBe(0)
        ->and($directory->paginate('', 'all', 'all', 'all', 'local:'.$s['company']->id)->total())->toBe(0);
});

it('keeps Receita establishment channels separate from human contacts', function (): void {
    $s = contactsReferenceV3Scenario();
    Establishment::query()->create([
        'company_id' => $s['company']->id,
        'cnpj' => '27441111000189',
        'order_number' => '0001',
        'check_digits' => '89',
        'type' => 'matrix',
        'phone_1' => '(45) 3244-7890',
        'email' => 'fiscal@agroindustria.test',
    ]);

    expect(app(ContactPeopleDirectory::class)->paginate()->total())->toBe(1);

    Livewire::actingAs(contactsReferenceV3Manager())
        ->test('pages::contacts.index')
        ->call('selectCompany', 'local', $s['company']->id)
        ->assertSee('Mariana Comercial')
        ->assertSee('Canais cadastrais da Receita')
        ->assertSee('fiscal@agroindustria.test')
        ->assertSee('Abrir dossiê');
});

it('updates the real phone verification through the new inspector', function (): void {
    $s = contactsReferenceV3Scenario();
    $manager = contactsReferenceV3Manager();

    Livewire::actingAs($manager)
        ->test('pages::contacts.index')
        ->call('showGlobalPerson', 'hubspot:'.$s['person']->hubspot_id)
        ->call('setWhatsappFromWorkspace', 'hubspot', $s['person']->id, 'mobile_phone', 'yes')
        ->assertHasNoErrors()
        ->assertSee('WhatsApp confirmado');

    expect(ContactPhoneVerification::query()->where('source_key', 'hubspot:'.$s['person']->hubspot_id)
        ->where('phone_normalized', '5545999992222')
        ->where('whatsapp_status', 'yes')
        ->exists())->toBeTrue();
});

it('does not allow a seller to inspect the manager-only contacts directory', function (): void {
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();

    $this->actingAs($seller)->get(route('contacts.index'))->assertForbidden();
});
