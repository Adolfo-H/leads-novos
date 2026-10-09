<?php

use App\Models\HubSpotTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.hubspot.access_token' => 'token-apenas-teste',
        'services.hubspot.base_url' => 'https://api.hubapi.com',
    ]);
});

it('reconciles a confirmed completed HubSpot task without deleting its history', function (): void {
    $user = User::factory()->create([
        'name' => 'Vendedor Teste',
        'hubspot_owner_id' => '123',
    ]);

    $task = HubSpotTask::query()->create([
        'hubspot_id' => 'test-complete-22',
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'due_at' => now()->subDay(),
        'assigned_to' => 'Vendedor Teste',
    ]);

    Http::fake([
        'api.hubapi.com/crm/v3/objects/tasks/*' => Http::response([
            'id' => 'test-complete-22',
            'archived' => false,
            'updatedAt' => now()->toIso8601String(),
            'properties' => [
                'hs_task_status' => 'COMPLETED',
                'hs_timestamp' => now()->subDay()->toIso8601String(),
                'hubspot_owner_id' => '123',
            ],
        ], 200),
    ]);

    $this->artisan('prospector:reconcile-hubspot-due-tasks', [
        'user' => $user->email,
        '--apply' => true,
        '--stale-minutes' => 0,
    ])->assertExitCode(0);

    $fresh = $task->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->is_open)->toBeFalse()
        ->and($fresh->status)->toBe('COMPLETED')
        ->and($fresh->completed_at)->not->toBeNull()
        ->and(data_get($fresh->raw_properties, 'prospector_reconciliation.previous.status'))
        ->toBe('NOT_STARTED');

    Http::assertSentCount(1);
});

it('moves a postponed task to its real due date without marking it completed', function (): void {
    $user = User::factory()->create([
        'name' => 'Vendedor Teste',
        'hubspot_owner_id' => '123',
    ]);
    $task = HubSpotTask::query()->create([
        'hubspot_id' => 'test-postponed-22',
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'is_overdue' => true,
        'due_at' => now()->subDay(),
        'assigned_to' => 'Vendedor Teste',
    ]);
    $newDue = now()->addWeek()->startOfHour();

    Http::fake([
        'api.hubapi.com/crm/v3/objects/tasks/*' => Http::response([
            'id' => 'test-postponed-22',
            'archived' => false,
            'properties' => [
                'hs_task_status' => 'NOT_STARTED',
                'hs_timestamp' => $newDue->toIso8601String(),
                'hubspot_owner_id' => '123',
            ],
        ], 200),
    ]);

    $this->artisan('prospector:reconcile-hubspot-due-tasks', [
        'user' => $user->email,
        '--apply' => true,
        '--stale-minutes' => 0,
    ])->assertExitCode(0);

    $fresh = $task->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->is_open)->toBeTrue()
        ->and($fresh->is_overdue)->toBeFalse()
        ->and($fresh->due_at->timestamp)->toBe($newDue->timestamp);
});

it('preserves the local task when the API does not confirm the remote state', function (): void {
    $user = User::factory()->create([
        'name' => 'Vendedor Teste',
        'hubspot_owner_id' => '123',
    ]);
    $task = HubSpotTask::query()->create([
        'hubspot_id' => 'test-missing-22',
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'due_at' => now()->subDay(),
        'assigned_to' => 'Vendedor Teste',
    ]);

    Http::fake([
        'api.hubapi.com/crm/v3/objects/tasks/*' => Http::response([], 404),
    ]);

    $this->artisan('prospector:reconcile-hubspot-due-tasks', [
        'user' => $user->email,
        '--apply' => true,
        '--stale-minutes' => 0,
    ])->assertExitCode(0);

    expect($task->fresh()->is_open)->toBeTrue()
        ->and($task->fresh()->status)->toBe('NOT_STARTED');
});

it('does not change any task without the explicit apply flag', function (): void {
    $user = User::factory()->create([
        'name' => 'Vendedor Teste',
        'hubspot_owner_id' => '123',
    ]);
    $task = HubSpotTask::query()->create([
        'hubspot_id' => 'test-dry-22',
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'due_at' => now()->subDay(),
        'assigned_to' => 'Vendedor Teste',
    ]);

    Http::fake([
        'api.hubapi.com/crm/v3/objects/tasks/*' => Http::response([
            'id' => 'test-dry-22',
            'properties' => [
                'hs_task_status' => 'COMPLETED',
                'hubspot_owner_id' => '123',
            ],
        ], 200),
    ]);

    $this->artisan('prospector:reconcile-hubspot-due-tasks', [
        'user' => $user->email,
        '--stale-minutes' => 0,
    ])->assertExitCode(0);

    expect($task->fresh()->is_open)->toBeTrue();
});

it('schedules background task reconciliation every thirty minutes', function (): void {
    $routes = file_get_contents(base_path('routes/console.php'));

    expect($routes)
        ->toContain('prospector:reconcile-hubspot-due-tasks --apply')
        ->toContain('everyThirtyMinutes()');
});
