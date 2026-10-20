<?php

use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\ContactPhoneVerification;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Services\Contacts\ContactPeopleDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @return array{total:int,page:int,per_page:int,rows:list<array<string,mixed>>} */
function pac09Snapshot(LengthAwarePaginator $result): array
{
    return [
        'total' => $result->total(),
        'page' => $result->currentPage(),
        'per_page' => $result->perPage(),
        'rows' => array_map(static fn ($row): array => get_object_vars($row), $result->items()),
    ];
}

it('preserva todas as colunas, origens, WhatsApp e ordenação em duas páginas', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '77112233', 'corporate_name' => 'Cooperativa Paginação 09',
    ]);
    $mirror = HubSpotCompany::query()->create([
        'hubspot_id' => '99009001', 'name' => 'Espelho Paginação 09',
        'lifecycle_stage' => 'customer',
    ]);

    for ($i = 1; $i <= 17; $i++) {
        $contact = HubSpotContact::query()->create([
            'hubspot_id' => '99009'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'first_name' => 'Pessoa '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'last_name' => 'Contato',
            'phone' => '(45) 3333-1010',
            'mobile_phone' => '(45) 99999-1010',
            'last_activity_at' => now()->subDays($i),
        ]);
        if ($i <= 3) {
            DB::table('hubspot_company_contact')->insert([
                'hubspot_contact_id' => $contact->id,
                'hubspot_company_id' => $mirror->id,
            ]);
        }
    }
    for ($i = 1; $i <= 12; $i++) {
        CommercialContact::query()->create([
            'company_id' => $company->id,
            'name' => 'Equipe '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'email' => 'equipe'.$i.'@example.invalid',
            'phone' => '(45) 3333-2020',
        ]);
    }
    Establishment::query()->create([
        'company_id' => $company->id, 'cnpj' => '77112233000122',
        'order_number' => '0001', 'check_digits' => '22',
        'type' => 'matrix', 'email' => 'fiscal@example.invalid', 'phone_1' => '4533333030',
    ]);
    Establishment::query()->create([
        'company_id' => $company->id, 'cnpj' => '77112233000203',
        'order_number' => '0002', 'check_digits' => '03',
        'type' => 'branch', 'phone_1' => '4533334040',
    ]);
    ContactPhoneVerification::query()->create([
        'source_key' => 'hubspot:990090001', 'phone_normalized' => '45999991010',
        'whatsapp_status' => 'yes',
    ]);

    $directory = app(ContactPeopleDirectory::class);
    foreach (['recent', 'name'] as $order) {
        foreach ([12, 25, 50] as $perPage) {
            foreach ([1, 2, 3, 4] as $page) {
                $old = $directory->paginate('', 'all', 'all', 'all', null, $page, $perPage, $order, true, null, null, false);
                $new = $directory->paginate('', 'all', 'all', 'all', null, $page, $perPage, $order, true, null, null, true);
                expect(pac09Snapshot($new))->toBe(pac09Snapshot($old));
            }
        }
    }
});

it('não altera buscas nem filtros comerciais', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '88115533', 'corporate_name' => 'Empresa Protegida 09',
    ]);
    CommercialContact::query()->create([
        'company_id' => $company->id, 'name' => 'Pessoa Manual Protegida',
        'phone' => '(45) 3333-7777',
    ]);
    HubSpotContact::query()->create([
        'hubspot_id' => '99009998', 'first_name' => 'Pessoa Sem Empresa',
    ]);

    $directory = app(ContactPeopleDirectory::class);
    foreach ([
        ['Pessoa', 'all', 'all', 'all'],
        ['', 'clients', 'all', 'all'],
        ['', 'deals', 'all', 'all'],
        ['', 'all', 'manual', 'all'],
        ['', 'unlinked', 'all', 'all'],
        ['', 'all', 'all', 'yes'],
    ] as [$search, $segment, $origin, $whatsapp]) {
        $old = $directory->paginate($search, $segment, $origin, $whatsapp, null, 1, 12, 'recent', true, null, null, false);
        $new = $directory->paginate($search, $segment, $origin, $whatsapp, null, 1, 12, 'recent', true, null, null, true);
        expect(pac09Snapshot($new))->toBe(pac09Snapshot($old));
    }
});
