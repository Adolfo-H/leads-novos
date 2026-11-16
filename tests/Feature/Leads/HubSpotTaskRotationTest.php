<?php

use App\Models\HubSpotTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.hubspot.access_token' => 'token-ficticio-para-teste',
        'services.hubspot.base_url' => 'https://api.hubapi.com',
    ]);

    Cache::forget('prospector:hubspot:due-tasks:cursor:v11:all');
});

function v11CreateTask(int $i, string $owner = 'Vendedor Teste'): HubSpotTask
{
    return HubSpotTask::query()->create([
        'hubspot_id' => 'rotation-v11-'.$i,
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'due_at' => now()->subDays(5)->addMinutes($i),
        'assigned_to' => $owner,
    ]);
}

it('visits every candidate across scheduled batches even when API returns 404', function (): void {
    for ($i = 1; $i <= 5; $i++) {
        v11CreateTask($i);
    }

    $seen = [];

    Http::fake(function (Request $request) use (&$seen) {
        $seen[] = basename((string) parse_url($request->url(), PHP_URL_PATH));

        return Http::response([], 404);
    });

    for ($round = 0; $round < 3; $round++) {
        $this->artisan('prospector:reconcile-hubspot-due-tasks', [
            '--apply' => true,
            '--limit' => 2,
            '--stale-minutes' => 0,
            '--samples' => 0,
        ])->assertExitCode(0);
    }

    expect($seen)->toBe([
        'rotation-v11-1',
        'rotation-v11-2',
        'rotation-v11-3',
        'rotation-v11-4',
        'rotation-v11-5',
        'rotation-v11-1',
    ]);

    expect(HubSpotTask::query()->where('is_open', true)->count())->toBe(5);
});

it('keeps the cursor unchanged during simulation', function (): void {
    for ($i = 1; $i <= 4; $i++) {
        v11CreateTask($i);
    }

    $seen = [];

    Http::fake(function (Request $request) use (&$seen) {
        $seen[] = basename((string) parse_url($request->url(), PHP_URL_PATH));

        return Http::response([], 404);
    });

    $this->artisan('prospector:reconcile-hubspot-due-tasks', [
        '--limit' => 2,
        '--stale-minutes' => 0,
        '--samples' => 0,
    ])->assertExitCode(0);

    expect(Cache::get('prospector:hubspot:due-tasks:cursor:v11:all'))->toBeNull();

    $this->artisan('prospector:reconcile-hubspot-due-tasks', [
        '--apply' => true,
        '--limit' => 2,
        '--stale-minutes' => 0,
        '--samples' => 0,
    ])->assertExitCode(0);

    $this->artisan('prospector:reconcile-hubspot-due-tasks', [
        '--apply' => true,
        '--limit' => 2,
        '--stale-minutes' => 0,
        '--samples' => 0,
    ])->assertExitCode(0);

    expect($seen)->toBe([
        'rotation-v11-1',
        'rotation-v11-2',
        'rotation-v11-1',
        'rotation-v11-2',
        'rotation-v11-3',
        'rotation-v11-4',
    ]);
});

it('maintains independent rotation for a named user', function (): void {
    $user = User::factory()->create([
        'name' => 'Vendedor Separado',
        'hubspot_owner_id' => 'owner-123',
    ]);

    v11CreateTask(101, 'Vendedor Separado');
    v11CreateTask(102, 'Vendedor Separado');
    v11CreateTask(1, 'Outro Responsavel');

    Http::fake([
        'api.hubapi.com/crm/v3/objects/tasks/*' => Http::response([], 404),
    ]);

    $this->artisan('prospector:reconcile-hubspot-due-tasks', [
        'user' => $user->email,
        '--apply' => true,
        '--limit' => 1,
        '--stale-minutes' => 0,
        '--samples' => 0,
    ])->assertExitCode(0);

    expect(
        Cache::get('prospector:hubspot:due-tasks:cursor:v11:all')
    )->toBeNull();

    expect(
        Cache::get(
            'prospector:hubspot:due-tasks:cursor:v11:'.$user->id
        )
    )->not->toBeNull();

    Http::assertSentCount(1);
});
