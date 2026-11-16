<?php

use App\Models\HubSpotTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('compares an old open task to a completed task online without changing either', function () {
    config([
        'services.hubspot.access_token' => 'token-falso-para-teste',
        'services.hubspot.base_url' => 'https://api.hubapi.com',
    ]);

    $user = User::factory()->create([
        'name' => 'Vendedor Teste',
        'email_verified_at' => now(),
        'hubspot_owner_id' => '123',
    ]);

    $task = HubSpotTask::query()->create([
        'hubspot_id' => '555001',
        'title' => 'Tarefa antiga',
        'assigned_to' => 'Vendedor Teste',
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'due_at' => now()->subDays(7),
    ]);

    Http::fake([
        'api.hubapi.com/crm/v3/objects/tasks/555001*' => Http::response([
            'id' => '555001',
            'archived' => false,
            'properties' => [
                'hs_task_status' => 'COMPLETED',
                'hs_timestamp' => now()->subDays(7)->toIso8601String(),
                'hubspot_owner_id' => '123',
            ],
        ], 200),
    ]);

    $this->artisan('prospector:audit-hubspot-tasks-live', [
        'user' => $user->email,
        '--limit' => 20,
    ])->assertExitCode(0);

    expect($task->fresh()->is_open)->toBeTrue();
    expect($task->fresh()->status)->toBe('NOT_STARTED');
    Http::assertSentCount(1);
    Http::assertSent(static fn ($request): bool => $request->method() === 'GET'
        && str_contains($request->url(), '/crm/v3/objects/tasks/555001')
    );
});

it('does not treat a 404 from the HubSpot API as confirmation of completed status', function () {
    config([
        'services.hubspot.access_token' => 'token-falso-para-teste',
        'services.hubspot.base_url' => 'https://api.hubapi.com',
    ]);

    $user = User::factory()->create([
        'name' => 'Vendedor Teste',
        'email_verified_at' => now(),
        'hubspot_owner_id' => '123',
    ]);

    $task = HubSpotTask::query()->create([
        'hubspot_id' => '555002',
        'assigned_to' => 'Vendedor Teste',
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'due_at' => now()->subDay(),
    ]);

    Http::fake([
        'api.hubapi.com/crm/v3/objects/tasks/555002*' => Http::response([], 404),
    ]);

    $this->artisan('prospector:audit-hubspot-tasks-live', [
        'user' => $user->email,
    ])->assertExitCode(0);

    expect($task->fresh()->is_open)->toBeTrue();
    Http::assertSentCount(1);
});
