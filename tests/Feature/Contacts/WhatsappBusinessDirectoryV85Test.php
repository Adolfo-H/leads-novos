<?php

use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\Contacts\WhatsappBusinessDirectoryV85;
use App\Services\Contacts\WhatsappPhoneRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function wa85Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
});

it('locates companies without domains, standalone deals and fiscal companies without inventing domains', function (): void {
    $local = Company::query()->create([
        'cnpj_root' => '12345678',
        'corporate_name' => 'SAAGROS COM IND IMPORTACAO E EXPORTACAO DE CEREAIS LTDA',
    ]);
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA85-COMPANY', 'name' => 'SAAGROS Comercial', 'domain' => null,
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'WA85-DEAL', 'name' => 'SAAGROS Prospect',
    ]);
    $service = app(WhatsappBusinessDirectoryV85::class);
    $results = $service->search('SAAGROS')->pluck('key')->all();

    expect($results)->toContain('local:'.$local->id)
        ->toContain('hubspot:'.$company->id)
        ->toContain('deal:'.$deal->id);

    $longName = $service->search('SAAGROS COM IND IMPORTACAO E EXPORTACAO DE CEREAIS LTDA')->pluck('key')->all();
    expect($longName)->toContain('local:'.$local->id)
        ->and($service->snapshot('local:'.$local->id)['domain'])->toBeNull()
        ->and($service->snapshot('deal:'.$deal->id)['site_hubspot_company_id'])->toBeNull();
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('shows real HubSpot company phones without requiring a website', function (): void {
    $manager = wa85Manager();
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA85-COMPANY2', 'name' => 'SAAGROS Sem Site', 'domain' => null,
    ]);
    $contact = HubSpotContact::query()->create([
        'hubspot_id' => 'WA85-C1', 'first_name' => 'Maria', 'mobile_phone' => '(45) 99999-1010',
    ]);
    $company->contacts()->attach($contact->id);
    app(WhatsappPhoneRegistry::class)->sync();

    $data = app(WhatsappBusinessDirectoryV85::class)->snapshot('hubspot:'.$company->id);
    expect($data['phone_total'])->toBe(1)
        ->and($data['phones']->first()->e164)->toBe('5545999991010')
        ->and($data['domain'])->toBeNull();

    Livewire::actingAs($manager)->test('pages::contacts.whatsapp')
        ->call('selectBusinessV85', 'hubspot:'.$company->id)
        ->assertHasNoErrors()
        ->assertSee('SAAGROS Sem Site')
        ->assertSee('99999-1010');
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('finds fiscal company contacts, and never copies contacts from a matching company name without trusted link', function (): void {
    $manager = wa85Manager();
    $local = Company::query()->create(['cnpj_root' => '87654321', 'corporate_name' => 'SAAGROS FISCAL LTDA']);
    $manual = CommercialContact::query()->create([
        'name' => 'Financeiro Fiscal', 'company_id' => $local->id,
        'created_by' => $manager->id, 'phone' => '(41) 98888-1212',
    ]);
    $other = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA85-OTHER', 'name' => 'SAAGROS FISCAL LTDA',
    ]);
    app(WhatsappPhoneRegistry::class)->sync();

    $service = app(WhatsappBusinessDirectoryV85::class);
    $localPhones = $service->snapshot('local:'.$local->id)['phones'];
    $hubspotPhones = $service->snapshot('hubspot:'.$other->id)['phones'];
    expect($localPhones)->toHaveCount(1)
        ->and($localPhones->first()->e164)->toBe('5541988881212')
        ->and($hubspotPhones)->toHaveCount(0);
    Http::assertNothingSent();
});

it('keeps ambiguous multi-company deals separate and uses only directly associated contacts', function (): void {
    $a = HubSpotCompany::query()->create(['hubspot_id' => 'WA85-MULTI-A', 'name' => 'Empresa A']);
    $b = HubSpotCompany::query()->create(['hubspot_id' => 'WA85-MULTI-B', 'name' => 'Empresa B']);
    $deal = HubSpotDeal::query()->create(['hubspot_id' => 'WA85-MULTI-DEAL', 'name' => 'Negociação SAAGROS']);
    $a->deals()->attach($deal->id, ['is_primary' => false]);
    $b->deals()->attach($deal->id, ['is_primary' => false]);
    $direct = HubSpotContact::query()->create(['hubspot_id' => 'WA85-DIRECT', 'mobile_phone' => '(45) 98888-4321']);
    $indirect = HubSpotContact::query()->create(['hubspot_id' => 'WA85-INDIRECT', 'mobile_phone' => '(45) 97777-4321']);
    $deal->contacts()->attach($direct->id);
    $a->contacts()->attach($indirect->id);
    app(WhatsappPhoneRegistry::class)->sync();

    $data = app(WhatsappBusinessDirectoryV85::class)->snapshot('deal:'.$deal->id);
    expect($data['phone_total'])->toBe(1)
        ->and($data['phones']->first()->e164)->toBe('5545988884321')
        ->and($data['linked_companies'])->toHaveCount(2);
    Http::assertNothingSent();
});

it('continues using the existing public-site flow for HubSpot companies with valid domains', function (): void {
    $manager = wa85Manager();
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA85-WITH-SITE', 'name' => 'Site SAAGROS',
        'domain' => 'saagros.example.org',
    ]);

    Livewire::actingAs($manager)->test('pages::contacts.whatsapp')
        ->call('selectBusinessV85', 'hubspot:'.$company->id)
        ->assertHasNoErrors()
        ->assertSet('publicCompanyId', $company->id)
        ->assertSet('publicBusinessKeyV85', null)
        ->assertSee('Pesquisar site desta empresa agora');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('rejects fabricated business selectors, without reading or changing another entity', function (): void {
    $manager = wa85Manager();
    expect(app(WhatsappBusinessDirectoryV85::class)->snapshot('hubspot:1 OR 1=1'))->toBeNull()
        ->and(app(WhatsappBusinessDirectoryV85::class)->snapshot('local:0'))->toBeNull();
    Livewire::actingAs($manager)->test('pages::contacts.whatsapp')
        ->call('selectBusinessV85', 'deal:999999999')
        ->assertNotFound();
    Http::assertNothingSent();
});
