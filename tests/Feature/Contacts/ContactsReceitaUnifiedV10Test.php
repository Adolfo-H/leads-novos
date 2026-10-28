<?php

use App\Models\CommercialContact;
use App\Models\Company;
use App\Models\EmailCampaignSuppression;
use App\Models\Establishment;
use App\Models\HubSpotContact;
use App\Models\User;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Contacts\ContactPeopleDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function unifiedReceitaFixture(): array
{
    $company = Company::query()->create([
        'cnpj_root' => '77999988',
        'corporate_name' => 'C.VALE COOPERATIVA AGROINDUSTRIAL',
    ]);
    $receipt = Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => '77999988000192',
        'type' => 'matrix',
        'order_number' => '0001',
        'check_digits' => '92',
        'email' => 'fiscal.cvale@example.test',
        'phone_1' => '(45) 3333-1234',
        'phone_2' => '(45) 3333-5678',
    ]);
    $phoneOnly = Establishment::query()->create([
        'company_id' => $company->id,
        'cnpj' => '77999988000273',
        'type' => 'branch',
        'order_number' => '0002',
        'check_digits' => '73',
        'phone_1' => '(45) 3000-0000',
    ]);
    HubSpotContact::query()->create([
        'hubspot_id' => '991103', 'first_name' => 'Marina', 'last_name' => 'Silva',
        'email' => 'marina@example.test', 'company_name' => 'Outra origem',
    ]);
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
    CommercialContact::query()->create([
        'company_id' => $company->id, 'name' => 'Equipe comercial',
        'email' => 'comercial@example.test', 'created_by' => $manager->id,
    ]);

    return compact('company', 'receipt', 'phoneOnly', 'manager');
}

it('lists Receita channels alongside HubSpot and manual contacts with accurate fiscal origin', function (): void {
    $f = unifiedReceitaFixture();
    $directory = app(ContactPeopleDirectory::class);
    expect($directory->paginate()->total())->toBe(4)
        ->and($directory->paginate('', 'receita')->total())->toBe(2)
        ->and($directory->paginate('', 'all', 'receita')->total())->toBe(2)
        ->and($directory->paginate('77999988000192')->total())->toBe(1)
        ->and($directory->paginate('C.VALE')->total())->toBe(3)
        ->and($directory->totals()['receita'])->toBe(2);

    $rows = collect($directory->paginate('', 'receita')->items());
    $matrix = $rows->firstWhere('source_key', 'receita:'.$f['receipt']->uuid);
    if (! $matrix instanceof stdClass) {
        throw new RuntimeException('Registro da matriz Receita não encontrado.');
    }
    expect($matrix->external_id)->toBe('77999988000192')
        ->and($matrix->person_type)->toBe('receita')
        ->and($directory->companyContext($matrix))->toBe('local:'.$f['company']->id);

    Livewire::actingAs($f['manager'])
        ->test('pages::contacts.index')
        ->call('chooseSegment', 'receita')
        ->assertSee('fiscal.cvale@example.test')
        ->assertSee('Receita Federal')
        ->assertSee('C.VALE');
});

it('allows only real Receita corporate emails to enter campaign drafts', function (): void {
    $f = unifiedReceitaFixture();
    $audience = app(CampaignAudience::class);
    $key = 'receita:'.$f['receipt']->uuid;
    $phoneKey = 'receita:'.$f['phoneOnly']->uuid;
    config()->set('campaign_mail.max_recipients', 50);

    expect($audience->allMatchingKeys('C.VALE', 'receita', 50))->toContain($key)
        ->not->toContain($phoneKey);
    $resolved = $audience->resolve([$key, $phoneKey, 'receita:00000000-0000-0000-0000-000000000000']);
    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]['email'])->toBe('fiscal.cvale@example.test')
        ->and($resolved[0]['company_name'])->toBe('C.VALE COOPERATIVA AGROINDUSTRIAL')
        ->and($resolved[0]['contact_name'])->toBe('Equipe');

    Mail::fake();
    Livewire::actingAs($f['manager'])
        ->test('pages::contacts.index')
        ->call('chooseSegment', 'receita')
        ->set('checkedContactKeys', [$key])
        ->call('createCampaignFromSelected')
        ->assertRedirect(route('campaigns.index'));
    Mail::assertNothingSent();
});

it('preserves unsubscribe and avoids duplicate corporate recipients across sources', function (): void {
    $f = unifiedReceitaFixture();
    $key = 'receita:'.$f['receipt']->uuid;
    HubSpotContact::query()->create([
        'hubspot_id' => '993311', 'first_name' => 'Representante',
        'email' => 'FISCAL.CVALE@example.test',
    ]);
    $audience = app(CampaignAudience::class);
    expect($audience->resolve(['hubspot:993311', $key]))->toHaveCount(1);

    EmailCampaignSuppression::query()->create([
        'normalized_email' => 'fiscal.cvale@example.test', 'reason' => 'unsubscribe',
    ]);
    $selected = $audience->resolve([$key]);
    expect($selected)->toHaveCount(1)
        ->and($selected[0]['suppressed'])->toBeTrue();
});

it('does not expose Receita recipient lists to unauthorized sellers', function (): void {
    unifiedReceitaFixture();
    $seller = User::factory()->create(['email_verified_at' => now()]);
    $seller->forceFill(['commercial_role' => User::ROLE_SELLER])->save();
    $this->actingAs($seller)->get(route('contacts.index'))->assertOk();
    $this->actingAs($seller)->get(route('campaigns.index'))->assertOk();
});
