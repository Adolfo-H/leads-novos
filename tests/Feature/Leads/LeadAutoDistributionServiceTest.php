<?php

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\User;
use App\Services\LeadAutoDistributionService;

function autoDistributionCompany(
    string $root,
    string $name,
    int $score = 80,
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

            'priority' => $score >= 80
                    ? 'very_high'
                    : 'high',

            'label' => 'Teste distribuição',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    return $company;
}

function autoDistributionStatus(
    Company $company,
    string $status,
): void {
    CompanyHubSpotLead::query()
        ->create([
            'company_id' => $company->id,

            'hubspot_company_id' => 'company-'
                .$company->cnpj_root,

            'hubspot_deal_id' => 'deal-'
                .$company->cnpj_root,

            'pipeline_id' => 'default',

            'deal_stage_id' => 'appointmentscheduled',

            'work_status' => $status,

            'open_task_count' => 0,

            'synced_at' => now(),

            'status_synced_at' => now(),

            'metadata' => [],
        ]);
}

function autoDistributionAssign(
    Company $company,
    User $user,
): void {
    $company
        ->leadWorkState()
        ->create([
            'assigned_user_id' => $user->id,

            'status' => 'new',
        ]);
}

it('balances new leads using the current portfolio load', function () {
    $manager =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $sellerA =
        User::factory()
            ->create([
                'name' => 'Vendedor A',

                'email_verified_at' => now(),
            ]);

    $sellerB =
        User::factory()
            ->create([
                'name' => 'Vendedor B',

                'email_verified_at' => now(),
            ]);

    $existing =
        autoDistributionCompany(
            '81111111',
            'Carteira Existente A'
        );

    autoDistributionAssign(
        $existing,
        $sellerA
    );

    $lead1 =
        autoDistributionCompany(
            '82222221',
            'Novo Score 100',
            100
        );

    $lead2 =
        autoDistributionCompany(
            '82222222',
            'Novo Score 90',
            90
        );

    $lead3 =
        autoDistributionCompany(
            '82222223',
            'Novo Score 80',
            80
        );

    $this->actingAs(
        $manager
    );

    $result =
        app(
            LeadAutoDistributionService::class
        )->distribute(
            [
                $sellerA->id,
                $sellerB->id,
            ],
            3,
        );

    expect(
        $result['distributed']
    )->toBe(3);

    expect(
        $result['remaining']
    )->toBe(0);

    /*
     * A começa com carga 1.
     * B começa com 0.
     *
     * Resultado final precisa ficar 2 x 2.
     */
    expect(
        $sellerA
            ->leadWorkStates()
            ->count()
    )->toBe(2);

    expect(
        $sellerB
            ->leadWorkStates()
            ->count()
    )->toBe(2);

    expect(
        $lead1
            ->leadWorkState
            ?->assigned_user_id
    )->toBe(
        $sellerB->id
    );

    expect(
        $lead2
            ->leadWorkState
            ?->assigned_user_id
    )->toBe(
        $sellerA->id
    );

    expect(
        $lead3
            ->leadWorkState
            ?->assigned_user_id
    )->toBe(
        $sellerB->id
    );
});

it('only distributes new and unassigned leads', function () {
    $seller =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $new =
        autoDistributionCompany(
            '83333331',
            'Novo Disponivel'
        );

    $contacting =
        autoDistributionCompany(
            '83333332',
            'Contato Sem Dono'
        );

    autoDistributionStatus(
        $contacting,
        'contacting'
    );

    $waiting =
        autoDistributionCompany(
            '83333333',
            'Waiting Sem Dono'
        );

    autoDistributionStatus(
        $waiting,
        'waiting'
    );

    $alreadyOwned =
        autoDistributionCompany(
            '83333334',
            'Novo Ja Distribuido'
        );

    autoDistributionAssign(
        $alreadyOwned,
        $seller
    );

    $service =
        app(
            LeadAutoDistributionService::class
        );

    expect(
        $service->candidatesCount()
    )->toBe(1);

    $result =
        $service->distribute(
            [
                $seller->id,
            ],
            50,
        );

    expect(
        $result['distributed']
    )->toBe(1);

    expect(
        $new
            ->leadWorkState
            ?->assigned_user_id
    )->toBe(
        $seller->id
    );

    expect(
        $contacting
            ->leadWorkState
    )->toBeNull();

    expect(
        $waiting
            ->leadWorkState
    )->toBeNull();
});

it('respects the distribution limit and score order', function () {
    $seller =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    $highest =
        autoDistributionCompany(
            '84444441',
            'Maior Score',
            99
        );

    $middle =
        autoDistributionCompany(
            '84444442',
            'Score Medio',
            80
        );

    $lowest =
        autoDistributionCompany(
            '84444443',
            'Menor Score',
            60
        );

    $result =
        app(
            LeadAutoDistributionService::class
        )->distribute(
            [
                $seller->id,
            ],
            2,
        );

    expect(
        $result['distributed']
    )->toBe(2);

    expect(
        $result['remaining']
    )->toBe(1);

    expect(
        $highest
            ->leadWorkState
            ?->assigned_user_id
    )->toBe(
        $seller->id
    );

    expect(
        $middle
            ->leadWorkState
            ?->assigned_user_id
    )->toBe(
        $seller->id
    );

    expect(
        $lowest
            ->leadWorkState
    )->toBeNull();
});

it('rejects an unverified salesperson', function () {
    $unverified =
        User::factory()
            ->create([
                'email_verified_at' => null,
            ]);

    autoDistributionCompany(
        '85555551',
        'Lead Distribuicao Invalida'
    );

    expect(
        fn () => app(
            LeadAutoDistributionService::class
        )->distribute(
            [
                $unverified->id,
            ],
            10,
        )
    )->toThrow(
        DomainException::class,
        'Um ou mais responsáveis selecionados não estão disponíveis.'
    );
});

it('rejects an empty salesperson selection', function () {
    expect(
        fn () => app(
            LeadAutoDistributionService::class
        )->distribute(
            [],
            10,
        )
    )->toThrow(
        DomainException::class,
        'Selecione pelo menos um responsável.'
    );
});
