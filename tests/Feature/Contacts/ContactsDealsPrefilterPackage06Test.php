<?php

use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Services\Contacts\ContactPeopleDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function pac06Snapshot(LengthAwarePaginator $page): array
{
    return [
        $page->total(),
        array_map(static fn ($row): array => get_object_vars($row), $page->items()),
    ];
}

it('mantém os negócios vinculados à pessoa ou ao espelho da empresa, sem inventar vínculos da Receita', function (): void {
    $company = Company::query()->create([
        'cnpj_root' => '88776655',
        'corporate_name' => 'Empresa de Teste da Receita',
    ]);
    Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => '88776655000122',
        'type' => 'matrix',
        'order_number' => '0001',
        'check_digits' => '22',
        'email' => 'receita@exemplo.invalid',
    ]);

    $mirror = HubSpotCompany::query()->create([
        'hubspot_id' => '9900601',
        'name' => 'Empresa Espelhada',
        'lifecycle_stage' => 'lead',
    ]);
    $secondMirror = HubSpotCompany::query()->create([
        'hubspot_id' => '9900602',
        'name' => 'Empresa Sem Negócio',
    ]);
    $linkedPerson = HubSpotContact::query()->create([
        'hubspot_id' => '9900611',
        'first_name' => 'Pessoa com Negócio',
    ]);
    $companyOnlyPerson = HubSpotContact::query()->create([
        'hubspot_id' => '9900612',
        'first_name' => 'Pessoa sem Negócio Direto',
    ]);
    $otherPerson = HubSpotContact::query()->create([
        'hubspot_id' => '9900613',
        'first_name' => 'Pessoa Sem Negócios',
    ]);
    DB::table('hubspot_company_contact')->insert([
        ['hubspot_contact_id' => $linkedPerson->id, 'hubspot_company_id' => $mirror->id],
        ['hubspot_contact_id' => $companyOnlyPerson->id, 'hubspot_company_id' => $mirror->id],
        ['hubspot_contact_id' => $otherPerson->id, 'hubspot_company_id' => $secondMirror->id],
    ]);
    $personalDeal = HubSpotDeal::query()->create([
        'hubspot_id' => '9900621', 'name' => 'Negócio da Pessoa',
    ]);
    $companyDeal = HubSpotDeal::query()->create([
        'hubspot_id' => '9900622', 'name' => 'Negócio da Empresa',
    ]);
    DB::table('hubspot_deal_contact')->insert([
        'hubspot_deal_id' => $personalDeal->id,
        'hubspot_contact_id' => $linkedPerson->id,
    ]);
    DB::table('hubspot_company_deal')->insert([
        'hubspot_company_id' => $mirror->id,
        'hubspot_deal_id' => $companyDeal->id,
    ]);
    CommercialContact::query()->create([
        'name' => 'Manual com Negócio da Empresa',
        'hubspot_company_external_id' => $mirror->hubspot_id,
    ]);
    CommercialContact::query()->create([
        'name' => 'Manual apenas da empresa local',
        'company_id' => $company->id,
    ]);
    CommercialContact::query()->create(['name' => 'Manual sem vínculo']);

    $directory = app(ContactPeopleDirectory::class);
    foreach (['recent', 'name'] as $order) {
        $old = $directory->paginate('', 'deals', 'all', 'all', null, 1, 12, $order, false, null, false);
        $new = $directory->paginate('', 'deals', 'all', 'all', null, 1, 12, $order, false, null, true);
        expect(pac06Snapshot($new))->toBe(pac06Snapshot($old));
        expect($new->total())->toBe(2);
        $types = array_map(static fn ($row): string => $row->person_type, $new->items());
        expect($types)->toContain('hubspot');
        expect($types)->toContain('manual');
        expect($types)->not->toContain('receita');
    }
});

it('não modifica a busca, origens, WhatsApp ou a segmentação geral', function (): void {
    HubSpotContact::query()->create([
        'hubspot_id' => '9900699',
        'first_name' => 'Sem Negócio',
    ]);
    $directory = app(ContactPeopleDirectory::class);
    foreach ([
        ['', 'all', 'all'],
        ['Sem', 'deals', 'all'],
        ['', 'deals', 'hubspot'],
        ['', 'clients', 'all'],
    ] as [$search, $segment, $origin]) {
        $old = $directory->paginate($search, $segment, $origin, 'all', null, 1, 12, 'recent', false, null, false);
        $new = $directory->paginate($search, $segment, $origin, 'all', null, 1, 12, 'recent', false, null, true);
        expect(pac06Snapshot($new))->toBe(pac06Snapshot($old));
    }
});
