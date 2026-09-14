<?php

use App\Jobs\EnrichImportItem;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('protects the prospecting page from guests', function () {
    $this
        ->get(
            route(
                'prospecting.index'
            )
        )
        ->assertRedirect(
            route('login')
        );
});

it('previews and executes prospecting from the page', function () {
    Queue::fake();

    $user =
        User::factory()->create([
            'email_verified_at' => now(),
        ]);

    config([
        'services.receita_local.base_url' => 'http://receita-data:8000',
    ]);

    Http::fake([
        'receita-data:8000/prospects*' => Http::response([
            'items' => [
                [
                    'cnpj_root' => '06315338',

                    'cnpj' => '06315338003134',

                    'corporate_name' => 'COFCO INTERNATIONAL BRASIL S.A.',

                    'state' => 'MT',

                    'matched_cnae' => '4622200',

                    'cnae_match_type' => 'primary',

                    'primary_cnae' => '4622200',

                    'share_capital' => 7011251666.72,

                    'size_code' => '05',

                    'legal_nature_code' => '2054',

                    'active_establishments' => 129,

                    'active_states' => 12,

                    'discovery_score' => 100,
                ],
            ],

            'count' => 1,

            'limit' => 50,

            'offset' => 0,

            'filters' => [
                'states' => [
                    'MT',
                ],

                'cnaes' => [
                    '4622200',
                ],
            ],
        ]),
    ]);

    Livewire::actingAs(
        $user
    )
        ->test(
            'pages::prospecting.index'
        )
        ->set(
            'limit',
            1
        )
        ->set(
            'selectedStates',
            [
                'MT',
            ]
        )
        ->set(
            'selectedCnaes',
            [
                '4622200',
            ]
        )
        ->call(
            'search'
        )
        ->assertHasNoErrors()
        ->assertSet(
            'newCount',
            1
        )
        ->assertSee(
            'COFCO INTERNATIONAL BRASIL S.A.'
        )
        ->call(
            'confirmExecution'
        )
        ->assertSet(
            'confirmingExecution',
            true
        )
        ->call(
            'executeProspecting'
        )
        ->assertHasNoErrors()
        ->assertSet(
            'confirmingExecution',
            false
        );

    expect(
        ImportBatch::query()
            ->where(
                'source_type',
                'prospecting'
            )
            ->count()
    )->toBe(1);

    Queue::assertPushed(
        EnrichImportItem::class,
        1
    );
});
