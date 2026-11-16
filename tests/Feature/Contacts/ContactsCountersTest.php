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

it('mantém os nove indicadores entre o cálculo agregado e a versão leve', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '88334422', 'corporate_name' => 'Empresa Cenário Pacote 07',
    ]);
    CompanyCrmCheck::query()->create([
        'company_id' => $company->id, 'provider' => 'hubspot',
        'status' => 'client', 'checked_at' => now(),
    ]);
    $mirror = HubSpotCompany::query()->create([
        'hubspot_id' => '9707001', 'name' => 'Espelho Pacote 07',
        'lifecycle_stage' => 'customer',
    ]);
    $linked = HubSpotContact::query()->create([
        'hubspot_id' => '9707002', 'first_name' => 'Pessoa Com Negócio',
        'phone' => '(45) 99999-2020', 'mobile_phone' => '(45) 99999-2020',
    ]);
    HubSpotContact::query()->create([
        'hubspot_id' => '9707003', 'first_name' => 'Pessoa Sem Empresa',
        'mobile_phone' => '(45) 99999-3030',
    ]);
    DB::table('hubspot_company_contact')->insert([
        'hubspot_contact_id' => $linked->id,
        'hubspot_company_id' => $mirror->id,
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => '9707004', 'name' => 'Negócio Pacote 07',
    ]);
    DB::table('hubspot_deal_contact')->insert([
        'hubspot_deal_id' => $deal->id,
        'hubspot_contact_id' => $linked->id,
    ]);
    $manual = CommercialContact::query()->create([
        'company_id' => $company->id, 'name' => 'Equipe Fiscal',
        'phone' => '(45) 3333-4040',
    ]);
    $receipt = Establishment::query()->create([
        'company_id' => $company->id, 'cnpj' => '88334422000155',
        'order_number' => '0001', 'check_digits' => '55',
        'type' => 'matrix', 'phone_1' => '(45) 3333-5050',
        'phone_2' => '(45) 3333-6060',
    ]);
    ContactPhoneVerification::query()->create([
        'source_key' => 'hubspot:9707002', 'phone_normalized' => '45999992020',
        'whatsapp_status' => 'yes',
    ]);
    ContactPhoneVerification::query()->create([
        'source_key' => 'manual:'.$manual->id, 'phone_normalized' => '4533334040',
        'whatsapp_status' => 'no',
    ]);
    ContactPhoneVerification::query()->create([
        'source_key' => 'receita:'.$receipt->uuid, 'phone_normalized' => '4533335050',
        'whatsapp_status' => 'no',
    ]);
    ContactPhoneVerification::query()->create([
        'source_key' => 'receita:'.$receipt->uuid, 'phone_normalized' => '4533336060',
        'whatsapp_status' => 'no',
    ]);

    $directory = app(ContactPeopleDirectory::class);
    $legacy = $directory->totalsAggregated();
    $fast = $directory->totalsLean();
    expect($fast)->toBe($legacy)
        ->and($fast['all'])->toBe(4)
        ->and($fast['clients'])->toBe(3)
        ->and($fast['deals'])->toBe(1)
        ->and($fast['unlinked'])->toBe(1)
        ->and($fast['wa_yes'])->toBe(1)
        ->and($fast['wa_unknown'])->toBe(1);
});

it('não duplica pessoas com telefone e celular iguais e respeita número vazio', function (): void {
    HubSpotContact::query()->create([
        'hubspot_id' => '9707990', 'first_name' => 'Telefone Repetido',
        'phone' => '+55 (45) 99999-2020', 'mobile_phone' => '+55 (45) 99999-2020',
    ]);
    HubSpotContact::query()->create([
        'hubspot_id' => '9707991', 'first_name' => 'Sem Número',
    ]);
    ContactPhoneVerification::query()->create([
        'source_key' => 'hubspot:9707990', 'phone_normalized' => '5545999992020',
        'whatsapp_status' => 'yes',
    ]);
    // Um registro anômalo pré-existente com número vazio também deve manter
    // a semântica SQL anterior: EXISTS pode encontrá-lo mesmo sem telefone.
    ContactPhoneVerification::query()->create([
        'source_key' => 'hubspot:9707991', 'phone_normalized' => '',
        'whatsapp_status' => 'yes',
    ]);
    $dir = app(ContactPeopleDirectory::class);
    expect($dir->totalsLean())->toBe($dir->totalsAggregated())
        ->and($dir->totalsLean()['wa_yes'])->toBe(2);
});
