<?php

use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\ContactPhoneVerification;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\Contacts\ContactDirectory;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function contactsManager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

/** @return array{company:Company,mirror:HubSpotCompany,contact:HubSpotContact} */
function contactsScenario(): array
{
    $company = Company::query()->create([
        'cnpj_root' => '12345678',
        'corporate_name' => 'COOPERATIVA DE TESTE CONTATOS',
    ]);
    $mirror = HubSpotCompany::query()->create([
        'hubspot_id' => '987000',
        'name' => 'COOPERATIVA DE TESTE CONTATOS',
        'company_id' => $company->id,
        'match_source' => 'hubspot_explicit_cnpj',
        'lifecycle_stage' => 'customer',
    ]);
    $contact = HubSpotContact::query()->create([
        'hubspot_id' => '360001',
        'first_name' => 'Mariana',
        'last_name' => 'Albuquerque',
        'email' => 'mariana@example.com',
        'phone' => '(45) 3234-5555',
        'mobile_phone' => '(45) 99999-1010',
        'job_title' => 'Gerente Fiscal',
    ]);
    $mirror->contacts()->attach($contact->id, ['is_primary' => true]);

    return compact('company', 'mirror', 'contact');
}

it('requires an authenticated manager to consult all commercial contacts', function () {
    $this->get('/contatos')->assertRedirect(route('login'));
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->get('/contatos')->assertForbidden();
    $this->actingAs(contactsManager())->get('/contatos')
        ->assertSuccessful()->assertSee('Central de Contatos');
});

it('shows company people from the mirror and receipt channels separately', function () {
    $models = contactsScenario();
    Establishment::query()->create([
        'company_id' => $models['company']->id,
        'cnpj' => '12345678000190',
        'order_number' => '0001',
        'check_digits' => '90',
        'type' => 'matrix',
        'phone_1' => '(45) 3211-2323',
        'email' => 'fiscal@empresa.teste',
    ]);
    $selected = app(ContactDirectory::class)->selected('local:'.$models['company']->id);
    expect($selected)->not->toBeNull();
    expect($selected['people']->total())->toBe(1);
    expect($selected['people']->items()[0]->person_name)->toBe('Mariana Albuquerque');
    expect($selected['receipt'])->toHaveCount(1);
    expect($selected['receipt'][0]['phone_1'])->toBe('(45) 3211-2323');

    Livewire::actingAs(contactsManager())
        ->test('pages::contacts.index')
        ->call('selectCompany', 'local', $models['company']->id)
        ->assertSee('Mariana Albuquerque')
        ->assertSee('Canais cadastrais da Receita')
        ->assertSee('fiscal@empresa.teste');
});

it('filters customer companies and companies with associated deals', function () {
    $m = contactsScenario();
    $m['company']->crmCheck()->create([
        'provider' => 'hubspot', 'status' => 'client', 'checked_at' => now(),
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => '50920', 'name' => 'Negócio comercial de teste',
        'stage_id' => 'appointmentscheduled', 'stage_label' => 'Prospects',
    ]);
    $m['mirror']->deals()->attach($deal->id, ['is_primary' => true]);
    $directory = app(ContactDirectory::class);
    expect($directory->companies('', 'clients')->total())->toBe(1);
    expect($directory->companies('', 'deals')->total())->toBe(1);
    expect($directory->selected('local:'.$m['company']->id)['deals'])->toHaveCount(1);
});

it('does not propagate a person through an unsafe fiscal link', function () {
    $m = contactsScenario();
    // Simula registros legados anteriores à defesa implementada no model.
    DB::table('hubspot_companies')->where('id', $m['mirror']->id)->update([
        'match_source' => 'hubspot_related_explicit_cnpj',
    ]);
    $directory = app(ContactDirectory::class);
    expect($directory->selected('local:'.$m['company']->id)['people']->total())->toBe(0);
    expect($directory->selected('hubspot:'.$m['mirror']->id)['people']->total())->toBe(1);
});

it('includes orphan HubSpot contacts without guessing their company', function () {
    HubSpotContact::query()->create([
        'hubspot_id' => '999991', 'first_name' => 'Sem', 'last_name' => 'Empresa',
        'company_name' => 'Nome de empresa informado sem vínculo',
    ]);
    $directory = app(ContactDirectory::class);
    expect($directory->orphanCount())->toBe(1);
    expect($directory->selected('orphan:0')['people']->total())->toBe(1);
    expect($directory->companies('', 'orphan')->total())->toBe(0);
});

it('stores WhatsApp confirmation per real phone and allows undo', function () {
    $m = contactsScenario();
    $user = contactsManager();
    $key = 'hubspot:'.$m['contact']->hubspot_id;
    Livewire::actingAs($user)
        ->test('pages::contacts.index')
        ->call('selectCompany', 'local', $m['company']->id)
        ->call('setWhatsapp', 'hubspot', $m['contact']->id, 'mobile_phone', 'yes')
        ->assertHasNoErrors()
        ->assertSee('WhatsApp confirmado');
    expect(ContactPhoneVerification::query()->where('source_key', $key)
        ->where('whatsapp_status', 'yes')->exists())->toBeTrue();
    Livewire::actingAs($user)->test('pages::contacts.index')
        ->call('selectCompany', 'local', $m['company']->id)
        ->call('setWhatsapp', 'hubspot', $m['contact']->id, 'mobile_phone', 'unknown');
    expect(ContactPhoneVerification::query()->where('source_key', $key)->count())->toBe(0);
});

it('creates and edits a manual contact without modifying the HubSpot mirror', function () {
    $m = contactsScenario();
    $user = contactsManager();
    Livewire::actingAs($user)->test('pages::contacts.index')
        ->call('selectCompany', 'local', $m['company']->id)
        ->call('startNewContact')
        ->set('formName', 'Luiz Operacional')
        ->set('formJob', 'Coordenador Tributário')
        ->set('formEmail', 'luiz@example.com')
        ->call('saveContact')
        ->assertHasNoErrors();
    $local = CommercialContact::query()->firstOrFail();
    expect($local->company_id)->toBe($m['company']->id);
    expect($local->name)->toBe('Luiz Operacional');
    expect(HubSpotContact::query()->count())->toBe(1);
    Livewire::actingAs($user)->test('pages::contacts.index')
        ->call('selectCompany', 'local', $m['company']->id)
        ->call('editManualContact', $local->id)
        ->set('formJob', 'Gerente Tributário')
        ->call('saveContact')
        ->assertHasNoErrors();
    expect($local->fresh()->job_title)->toBe('Gerente Tributário');
});

it('searches by the contact name without creating a second company', function () {
    $m = contactsScenario();
    $directory = app(ContactDirectory::class);
    $results = $directory->companies('Mariana', 'all');
    expect($results->total())->toBe(1);
    expect($results->items()[0]->record_id)->toBe($m['company']->id);
});
