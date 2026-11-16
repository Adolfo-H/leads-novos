<?php

use App\Jobs\ProcessHubSpotWebhookEvent;
use App\Models\Company;
use App\Models\CompanyLeadActivity;
use App\Models\HubSpotActivity;
use App\Models\HubSpotCompany;
use App\Models\HubSpotWebhookEvent;
use App\Services\HubSpotActivitySyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

it('preserves a long HubSpot task subject in mirror and company timeline', function (): void {
    config([
        'services.hubspot.access_token' => 'test-token',
        'services.hubspot.base_url' => 'https://api.hubapi.com',
    ]);

    $subject = str_repeat('Reunião de acompanhamento e proposta comercial – ', 11);
    expect(mb_strlen($subject))->toBeGreaterThan(180);

    $company = Company::query()->create([
        'cnpj_root' => '94321876',
        'corporate_name' => 'Empresa Teste Titulo Longo 39',
    ]);

    HubSpotCompany::query()->create([
        'hubspot_id' => 'v39-company',
        'company_id' => $company->id,
        'name' => 'Empresa Teste Titulo Longo 39',
    ]);

    $event = HubSpotWebhookEvent::query()->create([
        'event_key' => hash('sha256', 'v39-long-task'),
        'subscription_type' => 'object.propertyChange',
        'object_type' => 'task',
        'object_id' => 'v39-long-task',
        'status' => 'received',
        'payload' => [],
    ]);

    Http::fake([
        '*' => Http::response([
            'id' => 'v39-long-task',
            'createdAt' => '2026-10-23T12:00:00Z',
            'updatedAt' => '2026-10-23T12:10:00Z',
            'properties' => [
                'hs_timestamp' => '2026-10-23T12:00:00Z',
                'hs_task_subject' => $subject,
                'hs_task_body' => 'Tarefa sem truncamento no histórico.',
                'hs_task_status' => 'NOT_STARTED',
            ],
        ], 200),
    ]);

    $service = app(HubSpotActivitySyncService::class);
    $service->syncEvent($event, [$company->id]);
    $service->syncEvent($event, [$company->id]);

    $mirror = HubSpotActivity::query()->where('hubspot_id', 'v39-long-task')->firstOrFail();
    $projected = CompanyLeadActivity::query()
        ->where('company_id', $company->id)
        ->where('source', 'hubspot')
        ->where('source_object_type', 'task')
        ->where('source_object_id', 'v39-long-task')
        ->firstOrFail();

    // O sincronizador normaliza espacos nas extremidades do assunto (trim).
    // A validacao continua exigindo o texto completo, sem truncamento.
    $expectedSubject = trim($subject);

    expect($mirror->title)->toBe($expectedSubject)
        ->and($projected->title)->toBe($expectedSubject)
        ->and(CompanyLeadActivity::query()->where('source_object_id', 'v39-long-task')->count())->toBe(1)
        ->and(Schema::getColumnType('company_lead_activities', 'title'))->toBe('text')
        ->and(Schema::getColumnType('hubspot_activities', 'title'))->toBe('text');
});

it('previews an exhausted title error without scheduling anything and then retries it explicitly once', function (): void {
    Queue::fake();
    $event = HubSpotWebhookEvent::query()->create([
        'event_key' => hash('sha256', 'v39-retry'),
        'subscription_type' => 'object.propertyChange',
        'object_type' => 'task',
        'object_id' => 'v39-retry',
        'status' => 'failed',
        'attempts' => 10,
        'error' => 'SQLSTATE[22001] title exceeds varchar(150) in company_lead_activities',
        'payload' => [],
    ]);

    $this->artisan('hubspot:webhooks-title-retry', ['--ids' => (string) $event->id])
        ->assertExitCode(0);
    expect($event->refresh()->status)->toBe('failed')
        ->and($event->attempts)->toBe(10);
    Queue::assertNothingPushed();

    $this->artisan('hubspot:webhooks-title-retry', [
        '--ids' => (string) $event->id,
        '--apply' => true,
    ])->assertExitCode(0);

    expect($event->refresh()->status)->toBe('queued')
        ->and($event->attempts)->toBe(10);
    Queue::assertPushed(ProcessHubSpotWebhookEvent::class, 1);

    // Não pode duplicar ao repetir --apply.
    $this->artisan('hubspot:webhooks-title-retry', [
        '--ids' => (string) $event->id,
        '--apply' => true,
    ])->assertExitCode(0);
    Queue::assertPushed(ProcessHubSpotWebhookEvent::class, 1);
});

it('refuses to retry exhausted events with a different error', function (): void {
    Queue::fake();
    $event = HubSpotWebhookEvent::query()->create([
        'event_key' => hash('sha256', 'v39-unrelated'),
        'subscription_type' => 'object.propertyChange',
        'object_type' => 'task',
        'object_id' => 'v39-unrelated',
        'status' => 'failed',
        'attempts' => 10,
        'error' => 'HTTP 429',
        'payload' => [],
    ]);

    $this->artisan('hubspot:webhooks-title-retry', [
        '--ids' => (string) $event->id,
        '--apply' => true,
    ])->assertExitCode(0);
    expect($event->refresh()->status)->toBe('failed');
    Queue::assertNothingPushed();
});
