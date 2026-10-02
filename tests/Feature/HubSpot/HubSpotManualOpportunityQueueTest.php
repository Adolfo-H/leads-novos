<?php

use App\Jobs\SyncManualHubSpotOpportunity;
use App\Models\Company;
use App\Models\User;
use App\Services\HubSpotManualOpportunityQueueService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

function manualQueueCompany(
    string $root
): Company {
    $company =
        Company::query()->create([
            'cnpj_root' => $root,

            'corporate_name' => 'Empresa Fila Manual '
                .$root,
        ]);

    $company
        ->crmCheck()
        ->create([
            'provider' => 'hubspot',

            'status' => 'not_found',

            'contacted_count' => 0,

            'associated_deals_count' => 0,

            'metadata' => [],

            'checked_at' => now(),
        ]);

    return $company;
}

beforeEach(function () {
    config([
        'services.hubspot.manual_opportunity_enabled' => true,

        'services.hubspot.manual_opportunity_stale_minutes' => 20,

        'services.hubspot.lead_pipeline' => 'default',

        'services.hubspot.lead_initial_stage' => 'appointmentscheduled',
    ]);
});

it('queues a manual HubSpot opportunity and persists its state', function () {
    Queue::fake();

    $user =
        User::factory()->create();

    $company =
        manualQueueCompany(
            '93939393'
        );

    $sync =
        app(
            HubSpotManualOpportunityQueueService::class
        )->enqueue(
            company: $company,
            actor: $user,
        );

    expect(
        data_get(
            $sync->metadata,
            'manual_sync.status'
        )
    )->toBe(
        'queued'
    );

    expect(
        data_get(
            $sync->metadata,
            'manual_sync.initiated_by_user_id'
        )
    )->toBe(
        $user->id
    );

    expect(
        $sync->sync_error
    )->toBeNull();

    Queue::assertPushed(
        SyncManualHubSpotOpportunity::class,
        function (
            SyncManualHubSpotOpportunity $job
        ) use (
            $company,
            $user
        ): bool {
            return
                $job->companyId
                    === $company->id
                && $job->actorId
                    === $user->id;
        }
    );
});

it('does not enqueue the same manual opportunity twice while it is active', function () {
    Queue::fake();

    $user =
        User::factory()->create();

    $company =
        manualQueueCompany(
            '94949494'
        );

    $service =
        app(
            HubSpotManualOpportunityQueueService::class
        );

    $service->enqueue(
        company: $company,
        actor: $user,
    );

    expect(
        fn () => $service->enqueue(
            company: $company->fresh(),
            actor: $user,
        )
    )->toThrow(
        RuntimeException::class,
        'já está sendo processada'
    );

    Queue::assertPushed(
        SyncManualHubSpotOpportunity::class,
        1
    );
});

it('allows a failed manual opportunity to be queued again', function () {
    Queue::fake();

    $user =
        User::factory()->create();

    $company =
        manualQueueCompany(
            '95959595'
        );

    $service =
        app(
            HubSpotManualOpportunityQueueService::class
        );

    $sync =
        $service->enqueue(
            company: $company,
            actor: $user,
        );

    $metadata =
        $sync->metadata;

    data_set(
        $metadata,
        'manual_sync.status',
        'failed'
    );

    $sync->forceFill([
        'metadata' => $metadata,

        'sync_error' => 'Falha anterior',
    ])->save();

    $company->unsetRelation(
        'hubSpotLead'
    );

    /*
     * Queue::fake() não executa o primeiro Job.
     *
     * Portanto o lock do ShouldBeUnique continua
     * ativo mesmo após simularmos o status failed.
     *
     * Em produção esse lock é liberado pelo worker
     * quando o Job termina definitivamente.
     */
    app(
        UniqueLock::class
    )->release(
        new SyncManualHubSpotOpportunity(
            companyId: $company->id,
            actorId: $user->id,
        )
    );

    $second =
        $service->enqueue(
            company: $company,
            actor: $user,
        );

    expect(
        data_get(
            $second->metadata,
            'manual_sync.status'
        )
    )->toBe(
        'queued'
    );

    expect(
        $second->sync_error
    )->toBeNull();

    Queue::assertPushed(
        SyncManualHubSpotOpportunity::class,
        2
    );
});

it('does not queue manual opportunities when the feature is disabled', function () {
    Queue::fake();

    config([
        'services.hubspot.manual_opportunity_enabled' => false,
    ]);

    $user =
        User::factory()->create();

    $company =
        manualQueueCompany(
            '96969696'
        );

    expect(
        fn () => app(
            HubSpotManualOpportunityQueueService::class
        )->enqueue(
            company: $company,
            actor: $user,
        )
    )->toThrow(
        RuntimeException::class,
        'está desativada'
    );

    Queue::assertNothingPushed();
});

it('defines retry and timeout policy for the manual opportunity job', function () {
    $job =
        new SyncManualHubSpotOpportunity(
            companyId: 10,
            actorId: 20,
        );

    expect(
        $job->tries
    )->toBe(
        4
    );

    expect(
        $job->timeout
    )->toBe(
        180
    );

    expect(
        $job->uniqueFor
    )->toBe(
        600
    );

    expect(
        $job->backoff()
    )->toBe([
        60,
        300,
        900,
    ]);

    expect(
        $job->uniqueId()
    )->toBe(
        '10'
    );
});
