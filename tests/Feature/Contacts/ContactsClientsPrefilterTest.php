<?php

use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\CompanyCrmCheck;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Services\Contacts\ContactPeopleDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('mantém totais, vínculos e ordenação no filtro de clientes', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '77889900',
        'corporate_name' => 'Cliente da Base Fiscal',
    ]);
    CompanyCrmCheck::query()->create([
        'company_id' => $company->id,
        'provider' => 'hubspot',
        'status' => 'client',
        'checked_at' => now(),
    ]);

    // Primeiro o NÃO cliente para que MIN(id) seja a associação primária
    // de uma pessoa ligada às duas empresas.
    $nonCustomer = HubSpotCompany::query()->create([
        'hubspot_id' => '951002',
        'name' => 'Não Cliente',
        'lifecycle_stage' => 'lead',
    ]);
    $customer = HubSpotCompany::query()->create([
        'hubspot_id' => '951001',
        'name' => 'Cliente do HubSpot',
        'lifecycle_stage' => 'customer',
    ]);

    $people = [];
    foreach (['951101', '951102', '951103'] as $externalId) {
        $people[] = HubSpotContact::query()->create([
            'hubspot_id' => $externalId,
            'first_name' => 'Pessoa '.$externalId,
        ]);
    }
    DB::table('hubspot_company_contact')->insert([
        'hubspot_contact_id' => $people[0]->id,
        'hubspot_company_id' => $customer->id,
    ]);
    DB::table('hubspot_company_contact')->insert([
        'hubspot_contact_id' => $people[1]->id,
        'hubspot_company_id' => $nonCustomer->id,
    ]);
    // A regra final usa o vínculo primário MIN: mesmo com outra associação
    // cliente, a pessoa não pode ser forçada ao segmento de clientes.
    DB::table('hubspot_company_contact')->insert([
        'hubspot_contact_id' => $people[1]->id,
        'hubspot_company_id' => $customer->id,
    ]);

    CommercialContact::query()->create([
        'name' => 'Manual da Empresa Cliente',
        'company_id' => $company->id,
    ]);
    CommercialContact::query()->create([
        'name' => 'Manual HubSpot Cliente',
        'hubspot_company_external_id' => $customer->hubspot_id,
    ]);
    CommercialContact::query()->create(['name' => 'Manual sem vínculo']);
    Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => '77889900000111',
        'type' => 'matrix',
        'order_number' => '0001',
        'check_digits' => '11',
        'email' => 'contato@exemplo.invalid',
    ]);

    $directory = app(ContactPeopleDirectory::class);
    foreach (['recent', 'name'] as $order) {
        $original = $directory->paginate('', 'clients', 'all', 'all', null, 1, 12, $order, false, false);
        $optimized = $directory->paginate('', 'clients', 'all', 'all', null, 1, 12, $order, false, true);
        expect($optimized->total())->toBe($original->total())
            ->and(array_map(static fn ($row) => $row->source_key, $optimized->items()))
            ->toBe(array_map(static fn ($row) => $row->source_key, $original->items()))
            ->and(array_map(static fn ($row) => $row->company_label, $optimized->items()))
            ->toBe(array_map(static fn ($row) => $row->company_label, $original->items()));
    }
});

it('não muda filtros de clientes combinados com pesquisa e origem', function (): void {
    HubSpotContact::query()->create(['hubspot_id' => '951555', 'first_name' => 'Pessoa Cliente']);
    $directory = app(ContactPeopleDirectory::class);
    foreach ([
        ['Pessoa', 'all'],
        ['', 'hubspot'],
    ] as [$search, $origin]) {
        $original = $directory->paginate($search, 'clients', $origin, 'all', null, 1, 12, 'recent', false, false);
        $optimized = $directory->paginate($search, 'clients', $origin, 'all', null, 1, 12, 'recent', false, true);
        expect($optimized->total())->toBe($original->total())
            ->and(array_map(static fn ($row) => $row->source_key, $optimized->items()))
            ->toBe(array_map(static fn ($row) => $row->source_key, $original->items()));
    }
});
