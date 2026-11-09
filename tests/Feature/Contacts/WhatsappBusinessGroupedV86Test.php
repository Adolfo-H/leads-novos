<?php

use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotContact;
use App\Models\HubSpotDeal;
use App\Models\User;
use App\Services\Contacts\WhatsappBusinessDirectoryV86;
use App\Services\Contacts\WhatsappPhoneRegistry;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function wa86Manager(): User
{
    $actor = User::factory()->create(['email_verified_at' => now()]);
    $actor->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $actor;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    Queue::fake();
});

it('shows one Agroterenas result while retaining multiple deals as separate source records', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA86-AGROT-COMP', 'name' => 'AGROTERENAS S.A.',
        'domain' => 'agroterenas.com.br',
    ]);
    $fiscal = Company::query()->create([
        'cnpj_root' => '48984312', 'corporate_name' => 'AGROTERENAS S.A. CANA',
    ]);
    foreach (['Agroterenas - Licenciamento', 'AGROTERENAS S.A. - ACOMPANHAMENTO', 'AGROTERENAS S.A. - RETROATIVAS'] as $i => $name) {
        $deal = HubSpotDeal::query()->create(['hubspot_id' => 'WA86-D-'.($i + 1), 'name' => $name]);
        $company->deals()->attach($deal->id, ['is_primary' => true]);
    }

    $out = app(WhatsappBusinessDirectoryV86::class)->search('agroterenas');
    expect($out)->toHaveCount(1)
        ->and((string) $out->first()->key)->toBe('brand:AGROTERENAS');

    $summary = app(WhatsappBusinessDirectoryV86::class)->snapshot((string) $out->first()->key);
    expect($summary)->not->toBeNull()
        ->and($summary['grouped'])->toBeTrue()
        ->and($summary['deal_total'])->toBe(3)
        ->and(count($summary['records']))->toBe(5);

    // Agrupar na interface NÃO associa automaticamente CNPJ da Receita ao CRM.
    expect(HubSpotCompany::query()->findOrFail($company->id)->company_id)->toBeNull()
        ->and(Company::query()->findOrFail($fiscal->id)->cnpj_root)->toBe('48984312');
    Http::assertNothingSent();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

it('opens grouped results through Livewire without a 500 and shows real associated numbers', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA86-COMP-OPEN', 'name' => 'AGROTERENAS S.A.', 'domain' => null,
    ]);
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'WA86-DEAL-OPEN', 'name' => 'Agroterenas - Licenciamento',
    ]);
    $company->deals()->attach($deal->id, ['is_primary' => true]);
    $person = HubSpotContact::query()->create([
        'hubspot_id' => 'WA86-PERSON', 'first_name' => 'Contato do negócio',
        'mobile_phone' => '(45) 99999-1234',
    ]);
    $deal->contacts()->attach($person->id);
    app(WhatsappPhoneRegistry::class)->sync();

    Livewire::actingAs(wa86Manager())->test('pages::contacts.whatsapp')
        ->set('publicCompanyQuery', 'agroterenas')
        ->assertSee('AGROTERENAS S.A.')
        ->call('selectBusinessV85', 'brand:AGROTERENAS')
        ->assertHasNoErrors()
        ->assertSee('Empresa com registros comerciais agrupados')
        ->assertSee('Agroterenas - Licenciamento')
        ->assertSee('99999-1234');

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('does not borrow fiscal phone links from similarly named companies without verified identity', function (): void {
    $actor = wa86Manager();
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA86-SEPARATE-COMP', 'name' => 'AGROTERENAS S.A.',
    ]);
    $local = Company::query()->create([
        'cnpj_root' => '81234567', 'corporate_name' => 'AGROTERENAS S.A. CANA',
    ]);
    CommercialContact::query()->create([
        'name' => 'Contato fiscal', 'company_id' => $local->id,
        'created_by' => $actor->id, 'mobile_phone' => '(41) 98888-2222',
    ]);
    $companyPerson = HubSpotContact::query()->create([
        'hubspot_id' => 'WA86-PERSON-2', 'mobile_phone' => '(45) 99999-1111',
    ]);
    $company->contacts()->attach($companyPerson->id);
    app(WhatsappPhoneRegistry::class)->sync();

    $summary = app(WhatsappBusinessDirectoryV86::class)->snapshot('brand:AGROTERENAS');
    expect($summary['phone_total'])->toBe(2)
        ->and($summary['phones']->pluck('e164')->all())->toContain('5541988882222', '5545999991111')
        ->and(HubSpotCompany::query()->findOrFail($company->id)->company_id)->toBeNull();
});

it('keeps individual HubSpot business selection compatible with the existing public website workflow', function (): void {
    $company = HubSpotCompany::query()->create([
        'hubspot_id' => 'WA86-LEGACY', 'name' => 'Empresa com site registrado',
        'domain' => 'empresawhatsapp.com.br',
    ]);
    Livewire::actingAs(wa86Manager())->test('pages::contacts.whatsapp')
        ->call('selectBusinessV85', 'hubspot:'.$company->id)
        ->assertHasNoErrors()
        ->assertSet('publicCompanyId', $company->id)
        ->assertSet('publicBusinessKeyV85', null);
});
