<?php

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use Livewire\Livewire;

function v37CommercialUser(string $role): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['commercial_role' => $role])->save();
    return $user->refresh();
}

function v37Company(string $cnpjRoot, string $name, User $user): HubSpotCompany
{
    $company = Company::query()->create([
        'cnpj_root' => $cnpjRoot,
        'corporate_name' => $name,
        'source' => 'test',
    ]);
    $company->sdrScore()->create([
        'score' => 80,
        'priority' => 'high',
        'label' => 'Prioridade alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'v37-test',
        'metadata' => [],
        'calculated_at' => now(),
    ]);
    $company->leadWorkState()->create(['assigned_user_id' => $user->id]);
    return HubSpotCompany::query()->create([
        'company_id' => $company->id,
        'hubspot_id' => 'v37-company-'.$cnpjRoot,
        'name' => $name,
        'match_source' => 'manual_verified',
    ]);
}

function v37Deal(string $hubSpotId, string $stage, ?HubSpotCompany $company): void
{
    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => $hubSpotId,
        'name' => $hubSpotId,
        'stage_label' => $stage,
        'is_closed' => false,
        'is_closed_won' => false,
    ]);
    if ($company !== null) {
        $deal->companies()->attach($company->id, ['is_primary' => true]);
    }
}

it('separates the global HubSpot deal counts from the authenticated user portfolio', function (): void {
    $manager = v37CommercialUser(User::ROLE_MANAGER);
    $other = v37CommercialUser(User::ROLE_SELLER);
    $mine = v37Company('87210001', 'Empresa V37 Minha', $manager);
    $theirs = v37Company('87210002', 'Empresa V37 Outra Pessoa', $other);
    v37Deal('v37-mine', 'Prospects', $mine);
    v37Deal('v37-theirs', 'Prospects', $theirs);
    v37Deal('v37-unmatched', 'Negócio sem CNPJ', null);

    $page = Livewire::actingAs($manager)->test('pages::leads.index');
    $global = collect($page->get('globalHubSpotStageOptions'))->pluck('count', 'label');
    $personal = collect($page->get('crmStageOptions'))->pluck('count', 'label');

    expect((int) $global->get('Prospects'))->toBe(2)
        ->and((int) $global->get('Negócio sem CNPJ'))->toBe(1)
        ->and((int) $personal->get('Prospects'))->toBe(1)
        ->and($personal->has('Negócio sem CNPJ'))->toBeFalse();

    $page->assertSee('Visão geral')
        ->assertSee('Pipeline HubSpot · Minha carteira');
});

it('never widens the sales portfolio after a personal pipeline stage click', function (): void {
    $manager = v37CommercialUser(User::ROLE_MANAGER);
    $company = v37Company('87210003', 'Empresa V37 Filtro', $manager);
    v37Deal('v37-filter', 'Proposta apresentada', $company);
    $page = Livewire::actingAs($manager)->test('pages::leads.index');
    $options = collect($page->get('crmStageOptions'))->pluck('label');
    $index = $options->search('Proposta apresentada');
    expect($index)->not->toBeFalse();

    $page->call('applyCrmStageView', (int) $index)
        ->assertSet('owner', 'mine')
        ->assertSet('crm', 'stage:Proposta apresentada');
});

it('does not reveal global HubSpot totals to sellers but keeps their personal pipeline', function (): void {
    $seller = v37CommercialUser(User::ROLE_SELLER);
    $other = v37CommercialUser(User::ROLE_MANAGER);
    $mine = v37Company('87210004', 'Empresa V37 Vendedor', $seller);
    $theirs = v37Company('87210005', 'Empresa V37 Gestor', $other);
    v37Deal('v37-sales-deal', 'Qualificação', $mine);
    v37Deal('v37-private-deal', 'Negociação privada', $theirs);

    $page = Livewire::actingAs($seller)->test('pages::leads.index');
    expect($page->get('globalHubSpotStageOptions'))->toBe([]);
    $personal = collect($page->get('crmStageOptions'))->pluck('count', 'label');
    expect((int) $personal->get('Qualificação'))->toBe(1)
        ->and($personal->has('Negociação privada'))->toBeFalse();
    $page->assertDontSee('Visão geral');
});

it('keeps the Dashboard topbar unchanged while simplifying only the Leads title', function (): void {
    $layout = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));
    $view = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    expect($layout)->toContain("@unless (request()->routeIs('leads.index'))")
        ->and($view)->toContain('lf37-row-action')
        ->toContain('HubSpot ↗')
        ->toContain("@include('partials.leads-crm-stage-strip')")
        ->toContain("@include('partials.hubspot-unmatched-leads')");
});
