<?php

use App\Models\Company;
use App\Models\User;
use App\Services\LeadAutoDistributionService;
use Livewire\Livewire;

function previewDistributionCompany(
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

            'priority' => 'high',

            'label' => 'Teste preview',

            'is_eligible' => true,

            'is_provisional' => false,

            'factors' => [],

            'version' => 'test',

            'metadata' => [],

            'calculated_at' => now(),
        ]);

    return $company;
}

it('previews balanced distribution without changing ownership', function () {
    $sellerA =
        User::factory()
            ->create([
                'name' => 'Preview A',

                'email_verified_at' => now(),
            ]);

    $sellerB =
        User::factory()
            ->create([
                'name' => 'Preview B',

                'email_verified_at' => now(),
            ]);

    $existing =
        previewDistributionCompany(
            '86111111',
            'Carteira Preview Existente'
        );

    $existing
        ->leadWorkState()
        ->create([
            'assigned_user_id' => $sellerA->id,

            'status' => 'new',
        ]);

    $lead1 =
        previewDistributionCompany(
            '86222221',
            'Preview Novo 1',
            100
        );

    $lead2 =
        previewDistributionCompany(
            '86222222',
            'Preview Novo 2',
            90
        );

    $lead3 =
        previewDistributionCompany(
            '86222223',
            'Preview Novo 3',
            80
        );

    $preview =
        app(
            LeadAutoDistributionService::class
        )->preview(
            [
                $sellerA->id,
                $sellerB->id,
            ],
            3
        );

    expect(
        $preview['planned']
    )->toBe(3);

    expect(
        $preview['remaining_after']
    )->toBe(0);

    expect(
        $preview['balance_before']['spread']
    )->toBe(1);

    expect(
        $preview['balance_after']['spread']
    )->toBe(0);

    $users =
        collect(
            $preview['users']
        )->keyBy(
            'user_id'
        );

    expect(
        $users[$sellerA->id]['starting_load']
    )->toBe(1);

    expect(
        $users[$sellerA->id]['assigned_now']
    )->toBe(1);

    expect(
        $users[$sellerA->id]['ending_load']
    )->toBe(2);

    expect(
        $users[$sellerB->id]['starting_load']
    )->toBe(0);

    expect(
        $users[$sellerB->id]['assigned_now']
    )->toBe(2);

    expect(
        $users[$sellerB->id]['ending_load']
    )->toBe(2);

    expect(
        $lead1->leadWorkState()->exists()
    )->toBeFalse();

    expect(
        $lead2->leadWorkState()->exists()
    )->toBeFalse();

    expect(
        $lead3->leadWorkState()->exists()
    )->toBeFalse();
});

it('respects the preview limit', function () {
    $seller =
        User::factory()
            ->create([
                'email_verified_at' => now(),
            ]);

    for (
        $index = 1;
        $index <= 5;
        $index++
    ) {
        previewDistributionCompany(
            '8633333'.$index,
            'Preview Limite '.$index
        );
    }

    $preview =
        app(
            LeadAutoDistributionService::class
        )->preview(
            [
                $seller->id,
            ],
            2
        );

    expect(
        $preview['available_before']
    )->toBe(5);

    expect(
        $preview['planned']
    )->toBe(2);

    expect(
        $preview['remaining_after']
    )->toBe(3);
});

it('renders the preview for selected sellers', function () {
    $manager =
        User::factory()
            ->create([
                'commercial_role' => User::ROLE_MANAGER,

                'email_verified_at' => now(),
            ]);

    $sellerA =
        User::factory()
            ->create([
                'name' => 'Vendedor Preview A',

                'email_verified_at' => now(),
            ]);

    $sellerB =
        User::factory()
            ->create([
                'name' => 'Vendedor Preview B',

                'email_verified_at' => now(),
            ]);

    previewDistributionCompany(
        '86444441',
        'Lead Preview Tela 1'
    );

    previewDistributionCompany(
        '86444442',
        'Lead Preview Tela 2'
    );

    Livewire::actingAs(
        $manager
    )
        ->test(
            'pages::leads.management'
        )
        ->set(
            'distributionSellerIds',
            [
                (string) $sellerA->id,
                (string) $sellerB->id,
            ]
        )
        ->set(
            'distributionLimit',
            2
        )
        ->assertSee(
            'PRÉVIA DA RODADA'
        )
        ->assertSee(
            'Como a carteira ficará'
        )
        ->assertSee(
            'Vendedor Preview A'
        )
        ->assertSee(
            'Vendedor Preview B'
        )
        ->assertSee(
            'Diferença atual'
        )
        ->assertSee(
            'Diferença prevista'
        )
        ->assertSee(
            'Restariam sem responsável'
        );
});
