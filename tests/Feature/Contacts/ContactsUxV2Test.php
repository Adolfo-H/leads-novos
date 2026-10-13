<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\User;
use Livewire\Livewire;

function contactsUxV2Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

it('renders the new contact module without hiding the company selection', function () {
    $this->actingAs(contactsUxV2Manager())
        ->get(route('contacts.index'))
        ->assertSuccessful()
        ->assertSee('data-test-ux="contacts-ux-v2"', false)
        ->assertSee('Central de Contatos')
        ->assertSee('Empresas')
        ->assertSee('Clientes')
        ->assertSee('Com negócios');
});

it('shows only real linked HubSpot contacts and keeps fiscal channels separate', function () {
    $company = Company::query()->create([
        'cnpj_root' => '12345678',
        'corporate_name' => 'COOPERATIVA CONTATOS UX TESTE',
    ]);

    $mirror = HubSpotCompany::query()->create([
        'hubspot_id' => '889100',
        'name' => 'COOPERATIVA CONTATOS UX TESTE',
        'company_id' => $company->id,
        'match_source' => 'hubspot_explicit_cnpj',
    ]);

    $person = HubSpotContact::query()->create([
        'hubspot_id' => '889101',
        'first_name' => 'Marina',
        'last_name' => 'Fiscal',
        'job_title' => 'Gerente Fiscal',
        'email' => 'marina@example.com',
        'phone' => '(45) 3200-2300',
    ]);
    $mirror->contacts()->attach($person->id, ['is_primary' => true]);

    Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => '12345678000190',
        'order_number' => '0001',
        'check_digits' => '90',
        'type' => 'matrix',
        'phone_1' => '(45) 3333-1200',
        'email' => 'financeiro@cooperativa.test',
    ]);

    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => '889102',
        'name' => 'Negócio comercial UX',
        'stage_label' => 'Prospects',
    ]);
    $mirror->deals()->attach($deal->id, ['is_primary' => true]);

    Livewire::actingAs(contactsUxV2Manager())
        ->test('pages::contacts.index')
        ->call('selectCompany', 'local', $company->id)
        ->assertSee('COOPERATIVA CONTATOS UX TESTE')
        ->assertSee('Marina Fiscal')
        ->assertSee('Gerente Fiscal')
        ->assertSee('WhatsApp não verificado')
        ->assertSee('Canais cadastrais da Receita')
        ->assertSee('financeiro@cooperativa.test')
        ->assertSee('Negócio comercial UX')
        ->assertSee('Abrir dossiê');
});

it('keeps the selected company and local contact form behavior', function () {
    $company = Company::query()->create([
        'cnpj_root' => '99887766',
        'corporate_name' => 'EMPRESA COM CONTATO MANUAL',
    ]);

    Livewire::actingAs(contactsUxV2Manager())
        ->test('pages::contacts.index')
        ->call('selectCompany', 'local', $company->id)
        ->call('startNewContact')
        ->set('formName', 'Paulo Comercial')
        ->set('formEmail', 'paulo@example.com')
        ->call('saveContact')
        ->assertHasNoErrors()
        ->assertSee('Paulo Comercial')
        ->assertSee('Abrir detalhes');
});
