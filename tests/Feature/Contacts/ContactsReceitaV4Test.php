<?php

use App\Models\Company;
use App\Models\Establishment;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Contacts\ContactPeopleDirectory;
use App\Services\Contacts\ContactReceitaDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function contactsReceitaV4Manager(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

/** @return array{company:Company,matrix:Establishment,branch:Establishment} */
function contactsReceitaV4Data(): array
{
    $company = Company::query()->create([
        'cnpj_root' => '77863223',
        'corporate_name' => 'C.VALE COOPERATIVA TESTE',
    ]);
    $matrix = Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => '77863223000101',
        'order_number' => '0001',
        'check_digits' => '01',
        'type' => 'matrix',
        'fantasy_name' => 'C.VALE MATRIZ',
        'email' => 'matriz@cvale.example',
        'phone_1' => '(45) 3000-1111',
        'state' => 'PR',
        'municipality_name' => 'PALOTINA',
        'source' => 'receita_federal',
    ]);
    $branch = Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => '77863223000292',
        'order_number' => '0002',
        'check_digits' => '92',
        'type' => 'branch',
        'email' => 'filial@cvale.example',
        'phone_2' => '(44) 3200-2222',
        'state' => 'PR',
        'municipality_name' => 'TOLEDO',
        'source' => 'receita_federal',
    ]);
    Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => '77863223000373',
        'order_number' => '0003',
        'check_digits' => '73',
        'type' => 'branch',
        'state' => 'PR',
        'municipality_name' => 'MARECHAL CANDIDO RONDON',
    ]);

    return compact('company', 'matrix', 'branch');
}

it('protects Receita company channels behind manager middleware', function (): void {
    $this->get(route('contacts.receita'))->assertRedirect();

    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->get(route('contacts.receita'))->assertOk();

    $this->actingAs(contactsReceitaV4Manager())
        ->get(route('contacts.receita'))
        ->assertSuccessful()
        ->assertSee('Canais cadastrais da Receita');
});

it('finds C.Vale by normalized company name even without a HubSpot person', function (): void {
    contactsReceitaV4Data();
    $directory = app(ContactReceitaDirectory::class);
    $result = $directory->search('C.Vale');

    expect($directory->search('CVALE')->total())->toBe(2)
        ->and($result->total())->toBe(2)
        ->and(collect($result->items())->pluck('email')->all())
        ->toContain('matriz@cvale.example', 'filial@cvale.example')
        ->and(app(ContactPeopleDirectory::class)->paginate()->total())->toBe(2)
        ->and(app(ContactPeopleDirectory::class)->paginate('', 'receita')->total())->toBe(2);

    Livewire::actingAs(contactsReceitaV4Manager())
        ->test('pages::contacts.receita', ['searchTerm' => 'C.Vale'])
        ->assertSee('matriz@cvale.example')
        ->assertSee('filial@cvale.example')
        ->assertSee('(45) 3000-1111')
        ->assertSee('PALOTINA');
});

it('filters Receita channels by matriz filial and available email', function (): void {
    contactsReceitaV4Data();
    $directory = app(ContactReceitaDirectory::class);

    expect($directory->search('C.Vale', 'matrix')->total())->toBe(1)
        ->and($directory->search('C.Vale', 'branch')->total())->toBe(1)
        ->and($directory->search('C.Vale', 'all', 'email')->total())->toBe(2)
        ->and($directory->search('C.Vale', 'all', 'phone')->total())->toBe(2)
        ->and($directory->search('77863223000292')->total())->toBe(1);
});

it('does not browse millions of Receita companies without a search', function (): void {
    contactsReceitaV4Data();
    expect(app(ContactReceitaDirectory::class)->search('')->total())->toBe(0)
        ->and(app(ContactReceitaDirectory::class)->search('C')->total())->toBe(0);
});

it('does not infer fiscal association between HubSpot and Receita by company name', function (): void {
    $data = contactsReceitaV4Data();
    $mirror = HubSpotCompany::query()->create([
        'hubspot_id' => '7451178',
        'name' => 'C.VALE COOPERATIVA TESTE',
        'company_id' => $data['company']->id,
        'match_source' => 'hubspot_explicit_cnpj',
    ]);
    // Injeta legado inseguro apenas para demonstrar que ele nao e propagado.
    DB::table('hubspot_companies')->where('id', $mirror->id)->update([
        'match_source' => 'hubspot_related_explicit_cnpj',
    ]);
    HubSpotContact::query()->create([
        'hubspot_id' => '7451180',
        'first_name' => 'Pessoa',
        'email' => 'pessoa@hubspot.example',
    ]);

    expect(app(ContactReceitaDirectory::class)->search('C.Vale')->total())->toBe(2)
        ->and(app(ContactPeopleDirectory::class)->paginate()->total())->toBe(3)
        ->and(app(ContactPeopleDirectory::class)->paginate('', 'receita')->total())->toBe(2)
        ->and(app(ContactPeopleDirectory::class)->paginate('', 'hubspot')->total())->toBe(1);
});

it('keeps the Receita data page accessible without a duplicate action in Contacts', function (): void {
    $this->actingAs(contactsReceitaV4Manager())
        ->get(route('contacts.index'))
        ->assertSuccessful()
        ->assertDontSee('Canais Receita');

    $this->get(route('contacts.receita'))->assertSuccessful();
});
