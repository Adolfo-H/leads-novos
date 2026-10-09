<?php

use App\Models\HubSpotCompany;
use App\Models\HubSpotTask;
use App\Services\HubSpotWebhookMirrorSyncService;

it('creates association-only task references without claiming they are open', function (): void {
    $service = app(HubSpotWebhookMirrorSyncService::class);
    $method = new ReflectionMethod(HubSpotWebhookMirrorSyncService::class, 'localIds');

    $ids = $method->invoke($service, HubSpotTask::class, [
        'task-v13-reference-1',
        'task-v13-reference-2',
        'task-v13-reference-1',
    ]);

    expect($ids)->toHaveCount(2)
        ->and(HubSpotTask::query()->count())->toBe(2);

    $task = HubSpotTask::query()->where('hubspot_id', 'task-v13-reference-1')->firstOrFail();

    expect($task->is_open)->toBeFalse()
        ->and($task->is_overdue)->toBeFalse()
        ->and($task->status)->toBeNull()
        ->and($task->due_at)->toBeNull()
        ->and($task->completed_at)->toBeNull();
});

it('does not reset real task data after another association appears', function (): void {
    $real = HubSpotTask::query()->create([
        'hubspot_id' => 'task-v13-real',
        'title' => 'Retorno comercial confirmado',
        'status' => 'NOT_STARTED',
        'is_open' => true,
        'is_overdue' => false,
        'due_at' => now()->addDay(),
    ]);

    $service = app(HubSpotWebhookMirrorSyncService::class);
    $method = new ReflectionMethod(HubSpotWebhookMirrorSyncService::class, 'localIds');
    $ids = $method->invoke($service, HubSpotTask::class, ['task-v13-real']);

    expect($ids)->toBe([$real->id])
        ->and($real->refresh()->is_open)->toBeTrue()
        ->and($real->title)->toBe('Retorno comercial confirmado')
        ->and($real->status)->toBe('NOT_STARTED')
        ->and($real->due_at)->not->toBeNull();
});

it('preserves creation of company references without task-specific defaults', function (): void {
    $service = app(HubSpotWebhookMirrorSyncService::class);
    $method = new ReflectionMethod(HubSpotWebhookMirrorSyncService::class, 'localIds');
    $ids = $method->invoke($service, HubSpotCompany::class, ['company-v13-reference']);

    expect($ids)->toHaveCount(1)
        ->and(HubSpotCompany::query()->where('hubspot_id', 'company-v13-reference')->exists())->toBeTrue();
});
