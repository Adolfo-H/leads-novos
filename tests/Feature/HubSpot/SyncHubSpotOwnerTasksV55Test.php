<?php

use App\Models\HubSpotTask;
use App\Models\User;
use App\Services\DashboardHubSpotDueTasksV40Service;
use App\Services\LeadDueTaskQueueV54Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'app.timezone' => 'America/Sao_Paulo',
        'services.hubspot.access_token' => 'fake-token-v55',
        'services.hubspot.base_url' => 'https://api.hubapi.com',
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-26 10:26:00', 'America/Sao_Paulo'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function v55Owner(): User
{
    $user = User::factory()->create([
        'name' => 'Responsavel HubSpot V55',
        'email_verified_at' => now(),
        'hubspot_owner_id' => '1226951898',
    ]);
    $user->forceFill(['commercial_role' => User::ROLE_MANAGER])->save();

    return $user;
}

/** @return array<string, mixed> */
function v55Remote(int $number, string $status = 'NOT_STARTED'): array
{
    return [
        'id' => (string) (550000 + $number),
        'createdAt' => '2026-10-23T13:00:00Z',
        'updatedAt' => '2026-10-26T13:00:00Z',
        'archived' => false,
        'properties' => [
            'hubspot_owner_id' => '1226951898',
            'hs_timestamp' => $number <= 7 ? '2026-10-26T11:00:00Z' : '2026-10-26T12:00:00Z',
            'hs_task_status' => $status,
            'hs_task_subject' => 'Tarefa numero '.$number,
            'hs_task_type' => 'CALL',
        ],
    ];
}

it('confirms the missing eleventh task and applies all pages without duplicates', function (): void {
    $user = v55Owner();
    for ($i = 1; $i <= 10; $i++) {
        HubSpotTask::query()->create([
            'hubspot_id' => (string) (550000 + $i),
            'title' => 'Tarefa numero '.$i,
            'status' => 'NOT_STARTED',
            'is_open' => true,
            'due_at' => today()->setTime($i <= 7 ? 8 : 9, 0),
            'assigned_to' => $user->name,
            'raw_properties' => ['hubspot_owner_id' => (string) $user->hubspot_owner_id],
        ]);
    }
    expect(app(LeadDueTaskQueueV54Service::class)->counts($user))
        ->toBe(['total' => 10, 'overdue' => 10, 'today' => 0]);

    $first = [];
    $second = [];
    for ($i = 1; $i <= 7; $i++) {
        $first[] = v55Remote($i);
    }
    for ($i = 8; $i <= 11; $i++) {
        $second[] = v55Remote($i);
    }

    Http::fake(static function (Request $request) use ($first, $second) {
        if ($request->method() === 'POST' && str_contains($request->url(), '/objects/tasks/search')) {
            return Http::response(
                ($request->data()['after'] ?? null) === null
                    ? ['results' => $first, 'paging' => ['next' => ['after' => 'cursor-next']]]
                    : ['results' => $second],
                200
            );
        }

        // O id 550011 não tem associações locais. Não inventar Company.
        return Http::response(['results' => []], 200);
    });

    $this->artisan('prospector:sync-hubspot-owner-tasks', [
        'user' => $user->email, '--date' => '2026-10-26',
    ])->assertExitCode(0);
    expect(HubSpotTask::query()->count())->toBe(10);

    $this->artisan('prospector:sync-hubspot-owner-tasks', [
        'user' => $user->email, '--date' => '2026-10-26', '--apply' => true,
    ])->assertExitCode(0);

    expect(HubSpotTask::query()->count())->toBe(11)
        ->and(app(LeadDueTaskQueueV54Service::class)->counts($user))
        ->toBe(['total' => 11, 'overdue' => 11, 'today' => 0])
        ->and(app(DashboardHubSpotDueTasksV40Service::class)->forUser($user->id))
        ->toBe(['total' => 11, 'overdue' => 11, 'today' => 0]);

    Livewire::actingAs($user)->test('pages::leads.index')
        ->call('applyFigmaFollowUp', 'overdue')
        ->assertSee('Tarefa numero 11');

    // Idempotência: segunda aplicação não pode duplicar registros.
    $this->artisan('prospector:sync-hubspot-owner-tasks', [
        'user' => $user->email, '--date' => '2026-10-26', '--apply' => true,
    ])->assertExitCode(0);
    expect(HubSpotTask::query()->count())->toBe(11);

    Http::assertSent(static function (Request $request): bool {
        if (! str_contains($request->url(), '/objects/tasks/search')) {
            return false;
        }
        $filters = $request->data()['filterGroups'][0]['filters'] ?? [];
        $values = collect($filters)->pluck('value', 'propertyName');

        return $request->method() === 'POST'
            && $request->data()['limit'] === 200
            && $values->get('hubspot_owner_id') === '1226951898'
            && $values->get('hs_timestamp') !== null;
    });
});

it('keeps 08h and 09h in today only before their exact due time', function (): void {
    $user = v55Owner();
    foreach ([8, 9] as $hour) {
        HubSpotTask::query()->create([
            'hubspot_id' => 'v55-'.$hour,
            'status' => 'NOT_STARTED',
            'is_open' => true,
            'due_at' => today()->setTime($hour, 0),
            'assigned_to' => $user->name,
            'raw_properties' => ['hubspot_owner_id' => (string) $user->hubspot_owner_id],
        ]);
    }

    $v54 = app(LeadDueTaskQueueV54Service::class);
    $dashboard = app(DashboardHubSpotDueTasksV40Service::class);

    Carbon::setTestNow('2026-10-26 07:59:59');
    expect($v54->counts($user))->toBe(['total' => 2, 'overdue' => 0, 'today' => 2]);

    Carbon::setTestNow('2026-10-26 08:00:00');
    expect($v54->counts($user))->toBe(['total' => 2, 'overdue' => 1, 'today' => 1])
        ->and($dashboard->forUser($user->id))->toBe(['total' => 2, 'overdue' => 1, 'today' => 1]);

    Carbon::setTestNow('2026-10-26 09:00:00');
    expect($v54->counts($user))->toBe(['total' => 2, 'overdue' => 2, 'today' => 0]);
});

it('closes a locally open task only after HubSpot confirms completion', function (): void {
    $user = v55Owner();
    $task = HubSpotTask::query()->create([
        'hubspot_id' => '550001',
        'title' => 'Tarefa de follow-up',
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'due_at' => today()->setTime(8, 0),
        'assigned_to' => $user->name,
        'raw_properties' => ['hubspot_owner_id' => (string) $user->hubspot_owner_id],
    ]);
    Http::fake([
        'https://api.hubapi.com/crm/v3/objects/tasks/search' => Http::response([
            'results' => [v55Remote(1, 'COMPLETED'), v55Remote(2, 'COMPLETED')],
        ], 200),
    ]);

    $this->artisan('prospector:sync-hubspot-owner-tasks', ['user' => $user->email])->assertExitCode(0);
    expect($task->fresh()->is_open)->toBeTrue();

    $this->artisan('prospector:sync-hubspot-owner-tasks', ['user' => $user->email, '--apply' => true])->assertExitCode(0);
    expect($task->fresh()->is_open)->toBeFalse()
        ->and($task->fresh()->status)->toBe('COMPLETED')
        ->and(HubSpotTask::query()->where('hubspot_id', '550002')->exists())->toBeFalse();
});

it('repairs an existing task excluded from the V54 because its local owner is stale', function (): void {
    $user = v55Owner();
    $task = HubSpotTask::query()->create([
        'hubspot_id' => '550001',
        'title' => 'Tarefa existente com owner divergente',
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'due_at' => today()->setTime(8, 0),
        'assigned_to' => $user->name,
        'raw_properties' => ['hubspot_owner_id' => 'OUTRO-OWNER'],
    ]);
    expect(app(LeadDueTaskQueueV54Service::class)->counts($user)['total'])->toBe(0);
    Http::fake([
        'https://api.hubapi.com/crm/v3/objects/tasks/search' => Http::response([
            'results' => [v55Remote(1)],
        ], 200),
    ]);

    $this->artisan('prospector:sync-hubspot-owner-tasks', [
        'user' => $user->email, '--apply' => true,
    ])->assertExitCode(0);

    expect(HubSpotTask::query()->count())->toBe(1)
        ->and($task->fresh()->raw_properties['hubspot_owner_id'])->toBe('1226951898')
        ->and(app(LeadDueTaskQueueV54Service::class)->counts($user)['overdue'])->toBe(1);
});

it('rejects an incomplete API pagination without inserting a partial owner inventory', function (): void {
    $user = v55Owner();
    Http::fake([
        'https://api.hubapi.com/crm/v3/objects/tasks/search' => Http::response([
            'results' => [v55Remote(1)],
            'paging' => ['next' => ['after' => 'another-page']],
        ], 200),
    ]);
    $this->artisan('prospector:sync-hubspot-owner-tasks', [
        'user' => $user->email, '--apply' => true, '--max-pages' => 1,
    ])->assertExitCode(1);
    expect(HubSpotTask::query()->count())->toBe(0);
});

it('fails safely without credentials or on an invalid date', function (): void {
    $user = v55Owner();
    config(['services.hubspot.access_token' => '']);
    $this->artisan('prospector:sync-hubspot-owner-tasks', ['user' => $user->email])->assertExitCode(1);

    config(['services.hubspot.access_token' => 'fake-token']);
    $this->artisan('prospector:sync-hubspot-owner-tasks', [
        'user' => $user->email, '--date' => '2026-02-31',
    ])->assertExitCode(1);
    Http::assertNothingSent();
});

it('keeps V54 filters and the dashboard connected to the same task totals', function (): void {
    $lead = file_get_contents(resource_path('views/pages/leads/⚡index.blade.php'));
    $dashboard = file_get_contents(resource_path('views/livewire/dashboard-overview/strategic.blade.php'));
    $service = file_get_contents(app_path('Services/DashboardHubSpotDueTasksV40Service.php'));
    $schedule = file_get_contents(base_path('routes/console.php'));

    expect($lead)->toContain('public string $queueDuePeriod')
        ->and($lead)->toContain("#[Url]\n    public string \$queueDuePeriod")
        ->and($dashboard)->toContain("'queueDuePeriod' => 'today'")
        ->and($service)->toContain('LeadDueTaskQueueV54Service')
        ->and($schedule)->toContain('prospector:sync-hubspot-owner-tasks --apply');
});
