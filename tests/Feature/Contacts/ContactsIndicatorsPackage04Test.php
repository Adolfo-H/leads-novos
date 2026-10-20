<?php

use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\CompanyCrmCheck;
use App\Models\ContactPhoneVerification;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Services\Contacts\ContactPeopleDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('agrega os nove totais com a mesma regra dos filtros antigos', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '77889900',
        'corporate_name' => 'Empresa de Teste Contatos',
    ]);
    CompanyCrmCheck::query()->create([
        'company_id' => $company->id,
        'provider' => 'hubspot',
        'status' => 'client',
        'checked_at' => now(),
    ]);

    $mirror = HubSpotCompany::query()->create([
        'hubspot_id' => '9914401',
        'name' => 'Cliente Espelho',
        'lifecycle_stage' => 'customer',
    ]);
    $contactWithDeal = HubSpotContact::query()->create([
        'hubspot_id' => '9914402',
        'first_name' => 'Pessoa Com Negócio',
        'phone' => '(45) 99999-1111',
    ]);
    HubSpotContact::query()->create([
        'hubspot_id' => '9914403',
        'first_name' => 'Pessoa Sem Vínculo',
        'mobile_phone' => '(45) 98888-2222',
    ]);
    DB::table('hubspot_company_contact')->insert([
        'hubspot_company_id' => $mirror->id,
        'hubspot_contact_id' => $contactWithDeal->id,
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => '9914404',
        'name' => 'Negócio de Teste',
    ]);
    DB::table('hubspot_deal_contact')->insert([
        'hubspot_deal_id' => $deal->id,
        'hubspot_contact_id' => $contactWithDeal->id,
    ]);
    CommercialContact::query()->create([
        'name' => 'Contato Manual',
        'company_id' => $company->id,
    ]);
    Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => '77889900000111',
        'type' => 'matrix',
        'order_number' => '0001',
        'check_digits' => '11',
        'phone_1' => '(45) 3666-1000',
    ]);
    ContactPhoneVerification::query()->create([
        'source_key' => 'hubspot:9914402',
        'phone_normalized' => '45999991111',
        'whatsapp_status' => 'yes',
    ]);

    $directory = app(ContactPeopleDirectory::class);
    $expected = $directory->totals();
    $actual = $directory->totalsAggregated();
    expect($actual)->toBe($expected)
        ->and($actual['all'])->toBe(4)
        ->and($actual['clients'])->toBe(3)
        ->and($actual['deals'])->toBe(1)
        ->and($actual['unlinked'])->toBe(1)
        ->and($actual['wa_yes'])->toBe(1)
        ->and($actual['wa_unknown'])->toBe(2);

    // Confirma a equivalência também com os filtros originais. Não usa cache.
    $method = new ReflectionMethod(ContactPeopleDirectory::class, 'query');
    foreach (['clients', 'deals', 'unlinked', 'wa_yes', 'wa_unknown'] as $segment) {
        $query = $method->invoke($directory, '', $segment);
        expect($query->count())->toBe($actual[$segment]);
    }
});

it('não executa várias agregações pesadas para os nove indicadores', function (): void {
    $queries = 0;
    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    app(ContactPeopleDirectory::class)->totalsAggregated();
    expect($queries)->toBeLessThan(8);
});
