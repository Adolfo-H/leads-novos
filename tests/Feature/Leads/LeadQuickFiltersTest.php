<?php

use App\Models\Company;
use App\Models\CompanyCrmCheck;
use App\Models\CompanyHubSpotLead;
use App\Models\HubSpotCompany;
use App\Models\HubSpotDeal;
use App\Models\User;
use Livewire\Livewire;

function quickFilterCompany(
    string $root,
    string $name,
    int $score,
    string $priority,
): Company {
    $company =
        Company::query()
            ->create([
                'cnpj_root' => $root,
                'corporate_name' => $name,
            ]);

    $company
        ->sdrScore()
        ->create([
            'score' => $score,
            'priority' => $priority,
            'label' => 'Teste',
            'is_eligible' => true,
            'is_provisional' => false,
            'factors' => [],
            'version' => 'test',
            'metadata' => [],
            'calculated_at' => now(),
        ]);

    return $company;
}

it(
    'filters high priority leads by clicking the KPI',
    function (): void {
        $user =
            User::factory()
                ->create([
                    'email_verified_at' => now(),
                ]);

        quickFilterCompany(
            '81000001',
            'Empresa Prioridade Alta',
            82,
            'high',
        );

        quickFilterCompany(
            '81000002',
            'Empresa Prioridade Media',
            63,
            'medium',
        );

        Livewire::actingAs(
            $user
        )
            ->test(
                'pages::leads.index'
            )
            ->call(
                'applyKpiView',
                'high'
            )
            ->assertSet(
                'priority',
                'high'
            )
            ->assertSee(
                'Empresa Prioridade Alta'
            )
            ->assertDontSee(
                'Empresa Prioridade Media'
            );
    }
);

it(
    'filters leads by operational status from the KPI',
    function (): void {
        $user =
            User::factory()
                ->create([
                    'email_verified_at' => now(),
                ]);

        $waiting =
            quickFilterCompany(
                '82000001',
                'Empresa Aguardando Retorno',
                78,
                'high',
            );

        CompanyHubSpotLead::query()
            ->create([
                'company_id' => $waiting->id,

                'hubspot_company_id' => 'company-waiting',

                'hubspot_deal_id' => 'deal-waiting',

                'work_status' => 'waiting',

                'open_task_count' => 1,

                'last_task_due_at' => now()->addDay(),

                'synced_at' => now(),

                'status_synced_at' => now(),

                'metadata' => [],
            ]);

        $contacting =
            quickFilterCompany(
                '82000002',
                'Empresa Em Contato',
                78,
                'high',
            );

        CompanyHubSpotLead::query()
            ->create([
                'company_id' => $contacting->id,

                'hubspot_company_id' => 'company-contacting',

                'hubspot_deal_id' => 'deal-contacting',

                'work_status' => 'contacting',

                'open_task_count' => 0,

                'synced_at' => now(),

                'status_synced_at' => now(),

                'metadata' => [],
            ]);

        Livewire::actingAs(
            $user
        )
            ->test(
                'pages::leads.index'
            )
            ->call(
                'applyKpiView',
                'waiting'
            )
            ->assertSet(
                'workStatus',
                'waiting'
            )
            ->assertSee(
                'Empresa Aguardando Retorno'
            )
            ->assertDontSee(
                'Empresa Em Contato'
            );
    }
);

it(
    'filters leads by a real HubSpot deal stage',
    function (): void {
        // v382-portfolio-stage-filter: usa o mirror REAL e a carteira, não
        // um snapshot desatualizado de CompanyCrmCheck nem uma etapa por índice fixo.
        $manager = User::factory()->create(['email_verified_at' => now()]);
        $manager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();
        $otherManager = User::factory()->create(['email_verified_at' => now()]);
        $otherManager->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

        $qualified = quickFilterCompany('83000001', 'Empresa Etapa Qualificada', 80, 'high');
        $differentStage = quickFilterCompany('83000002', 'Empresa Outra Etapa', 80, 'high');
        $anotherPortfolio = quickFilterCompany('83000003', 'Empresa Qualificada Outra Carteira', 80, 'high');

        $cases = [
            [$qualified, $manager, 'Leds qualificado', 'qualified-stage-v382'],
            [$differentStage, $manager, 'Reunião realizada', 'meeting-stage-v382'],
            [$anotherPortfolio, $otherManager, 'Leds qualificado', 'outside-stage-v382'],
        ];

        foreach ($cases as [$company, $assigned, $stage, $suffix]) {
            $company->leadWorkState()->create(['assigned_user_id' => $assigned->id]);

            $hubSpot = HubSpotCompany::query()->create([
                'company_id' => $company->id,
                'hubspot_id' => 'company-'.$suffix,
                'name' => $company->corporate_name,
                'match_source' => 'manual_verified',
            ]);

            $deal = HubSpotDeal::query()->create([
                'hubspot_id' => 'deal-'.$suffix,
                'name' => 'Negócio '.$stage,
                'stage_label' => $stage,
                'pipeline_id' => 'default',
                'is_closed' => false,
                'is_closed_won' => false,
            ]);

            $deal->companies()->attach($hubSpot->id, ['is_primary' => true]);
        }

        $page = Livewire::actingAs($manager)->test('pages::leads.index');
        $options = collect($page->get('crmStageOptions'))->pluck('label');
        $index = $options->search('Leds qualificado');
        expect($index)->not->toBeFalse();

        $page->call('applyCrmStageView', (int) $index)
            ->assertSet('crm', 'stage:Leds qualificado')
            ->assertSet('owner', 'mine')
            ->assertSee('Empresa Etapa Qualificada')
            ->assertDontSee('Empresa Outra Etapa')
            ->assertDontSee('Empresa Qualificada Outra Carteira');
    }
);
