<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function v255ReprospectionCompany(string $root, string $name, string $status, ?DateTimeInterface $lastActivity): Company
{
    $company = Company::query()->create([
        'cnpj_root' => $root,
        'corporate_name' => $name,
        'source' => 'test',
    ]);

    $company->sdrScore()->create([
        'score' => 82,
        'priority' => 'high',
        'label' => 'Alta',
        'is_eligible' => true,
        'is_provisional' => false,
        'factors' => [],
        'version' => 'v255',
        'metadata' => [],
        'calculated_at' => now(),
    ]);

    CompanyHubSpotLead::query()->create([
        'company_id' => $company->id,
        'hubspot_company_id' => 'v255-company-'.$root,
        'hubspot_deal_id' => 'v255-deal-'.$root,
        'work_status' => $status,
        'last_activity_at' => $lastActivity,
        'open_task_count' => 0,
        'metadata' => [],
    ]);

    return $company;
}

function v255AddCommercialStage(Company $company, string $stage, string $suffix): void
{
    $hub = HubSpotCompany::query()->create([
        'hubspot_id' => 'v255-hub-'.$suffix,
        'company_id' => $company->id,
        'match_source' => 'manual_verified',
        'name' => $company->corporate_name,
    ]);

    $deal = HubSpotDeal::query()->create([
        'hubspot_id' => 'v255-hs-deal-'.$suffix,
        'name' => 'Negócio '.$suffix,
        'stage_label' => $stage,
        'is_closed_won' => false,
    ]);

    $deal->companies()->attach($hub->id, ['is_primary' => true]);
}

it('mostra somente leads com inatividade comprovada no filtro de reprospecção, sem recusados ou descartados', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    v255ReprospectionCompany('93255001', 'V255 Reprospecção Antiga', 'future', now()->subDays(125));
    v255ReprospectionCompany('93255002', 'V255 Em Contato Antigo', 'contacting', now()->subDays(105));
    v255ReprospectionCompany('93255003', 'V255 Recusado Antigo', 'refused', now()->subDays(400));
    v255ReprospectionCompany('93255004', 'V255 Descartado Antigo', 'discarded', now()->subDays(400));
    v255ReprospectionCompany('93255005', 'V255 Contato Recente', 'future', now()->subDays(8));
    v255ReprospectionCompany('93255006', 'V255 Sem Data Segura', 'future', null);

    Livewire::actingAs($manager)
        ->test('pages::leads.index')
        ->set('crmSituation', 'reprospecting')
        ->assertSee('V255 Reprospecção Antiga')
        ->assertSee('V255 Em Contato Antigo')
        ->assertSeeHtml('lv13-status--reprospecting')
        ->assertDontSee('V255 Recusado Antigo')
        ->assertDontSee('V255 Descartado Antigo')
        ->assertDontSee('V255 Contato Recente')
        ->assertDontSee('V255 Sem Data Segura');
});

it('não inclui negócios marcados como Recusou ou Descartes no espelho HubSpot', function (): void {
    $manager = User::factory()->create(['email_verified_at' => now()]);
    $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    $recusado = v255ReprospectionCompany('93255007', 'V255 Negócio Recusado', 'future', now()->subDays(150));
    $descartes = v255ReprospectionCompany('93255008', 'V255 Negócio Descartes', 'future', now()->subDays(150));
    $valido = v255ReprospectionCompany('93255009', 'V255 Negócio Prospect', 'future', now()->subDays(150));

    v255AddCommercialStage($recusado, 'Recusou', 'recusado');
    v255AddCommercialStage($descartes, 'Descartes', 'descartes');
    v255AddCommercialStage($valido, 'Prospects', 'prospects');

    Livewire::actingAs($manager)
        ->test('pages::leads.index')
        ->set('crmSituation', 'reprospecting')
        ->assertDontSee('V255 Negócio Recusado')
        ->assertDontSee('V255 Negócio Descartes')
        ->assertSee('V255 Negócio Prospect');
});
