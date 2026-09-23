<?php

use App\Jobs\RefreshCompanyFromHubSpot;
use App\Models\Company;
use App\Models\HubSpotRefreshRun;
use App\Models\User;
use App\Services\HubSpotRefreshRunService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function hubSpotProgressCompany(
    string $root,
    string $name,
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
            'score' => 75,

            'priority' => 'high',

            'label' => 'Prioridade alta',

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
    'creates a tracked HubSpot refresh run and sends the tracking item to each job',
    function (): void {
        Queue::fake();

        $manager =
            User::factory()
                ->create([
                    'commercial_role' => User::ROLE_MANAGER,
                ]);

        $one =
            hubSpotProgressCompany(
                '91111111',
                'Empresa Refresh Um'
            );

        $two =
            hubSpotProgressCompany(
                '92222222',
                'Empresa Refresh Dois'
            );

        Livewire::actingAs(
            $manager
        )
            ->test(
                'pages::leads.index'
            )
            ->call(
                'refreshAllHubSpotData'
            );

        $run =
            HubSpotRefreshRun::query()
                ->firstOrFail();

        expect(
            $run->status
        )->toBe(
            'running'
        );

        expect(
            $run->total
        )->toBe(
            2
        );

        expect(
            $run
                ->items()
                ->count()
        )->toBe(
            2
        );

        Queue::assertPushed(
            RefreshCompanyFromHubSpot::class,
            2
        );

        $itemIds =
            $run
                ->items()
                ->pluck(
                    'id'
                )
                ->all();

        Queue::assertPushed(
            RefreshCompanyFromHubSpot::class,
            function (
                RefreshCompanyFromHubSpot $job
            ) use (
                $one,
                $two,
                $itemIds
            ): bool {
                return
                    in_array(
                        $job->companyId,
                        [
                            $one->id,
                            $two->id,
                        ],
                        true
                    )
                    && $job->refreshItemId !== null
                    && in_array(
                        $job->refreshItemId,
                        $itemIds,
                        true
                    );
            }
        );
    }
);

it(
    'updates progress counters when refresh items finish',
    function (): void {
        Queue::fake();

        $manager =
            User::factory()
                ->create([
                    'commercial_role' => User::ROLE_MANAGER,
                ]);

        $one =
            hubSpotProgressCompany(
                '93333333',
                'Empresa Com Alteracao'
            );

        $two =
            hubSpotProgressCompany(
                '94444444',
                'Empresa Sem Alteracao'
            );

        $service =
            app(
                HubSpotRefreshRunService::class
            );

        $run =
            $service->start(
                companyIds: [
                    $one->id,
                    $two->id,
                ],

                userId: $manager->id,
            );

        $items =
            $run
                ->items()
                ->orderBy(
                    'id'
                )
                ->get();

        expect(
            $items
        )->toHaveCount(
            2
        );

        $service->markRunning(
            $items[0]->id
        );

        $service->complete(
            itemId: $items[0]->id,

            changes: [
                [
                    'key' => 'work_status',

                    'label' => 'Acompanhamento',

                    'before' => 'Aguardando retorno',

                    'after' => 'Em contato',
                ],
            ],
        );

        $service->markRunning(
            $items[1]->id
        );

        $service->complete(
            itemId: $items[1]->id,

            changes: [],
        );

        $run =
            $run->refresh();

        expect(
            $run->processed
        )->toBe(
            2
        );

        expect(
            $run->changed
        )->toBe(
            1
        );

        expect(
            $run->unchanged
        )->toBe(
            1
        );

        expect(
            $run->failed
        )->toBe(
            0
        );

        expect(
            $run->status
        )->toBe(
            'completed'
        );

        expect(
            data_get(
                $run->summary,
                'categories.0.label'
            )
        )->toBe(
            'Acompanhamento'
        );

        expect(
            data_get(
                $run->summary,
                'categories.0.count'
            )
        )->toBe(
            1
        );
    }
);
