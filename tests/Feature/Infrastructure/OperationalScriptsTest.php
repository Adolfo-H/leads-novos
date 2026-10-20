<?php

use App\Jobs\HubSpotQueueHeartbeat;
use App\Jobs\QueueWorkerHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

it('keeps local startup HubSpot sync disabled unless explicitly configured', function () {
    $script = file_get_contents(base_path('start.sh'));
    $environment = file_get_contents(base_path('.env.example'));

    expect($script)->not->toBeFalse();
    expect($environment)->not->toBeFalse();
    expect($script)->toContain('HUBSPOT_STARTUP_SYNC_ENABLED');
    expect($script)->toContain("HUBSPOT_STARTUP_SYNC_ENABLED \\\n        \"false\"");
    expect($script)->toContain('[ "$STARTUP_LIMIT" -gt 100 ]');
    expect($environment)->toContain('HUBSPOT_STARTUP_SYNC_ENABLED=false');
    expect($environment)->toContain('HUBSPOT_STARTUP_SYNC_LIMIT=100');
    expect($script)->not->toContain('php artisan hubspot:health-ping \\');
});

it('runs update-safe in dry-run with a fake Docker command only', function () {
    $directory = sys_get_temp_dir().'/prospector-update-safe-'.bin2hex(random_bytes(8));
    $docker = $directory.'/docker';
    $dockerLog = $directory.'/docker.log';

    mkdir($directory, 0700, true);
    file_put_contents(
        $docker,
        "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$OPS_TEST_DOCKER_LOG\"\nexit 0\n"
    );
    chmod($docker, 0700);

    try {
        $process = new Process(
            [
                'bash',
                base_path('tools/ops/update-safe.sh'),
                '--dry-run',
            ],
            base_path(),
            [
                'PATH' => $directory.PATH_SEPARATOR.getenv('PATH'),
                'OPS_TEST_DOCKER_LOG' => $dockerLog,
            ],
        );

        $process->run();

        expect($process->getExitCode())->toBe(
            0,
            $process->getOutput().$process->getErrorOutput(),
        );
        expect($process->getOutput())->toContain('DRY-RUN concluído');

        $dockerCalls = file_get_contents($dockerLog);
        expect($dockerCalls)->toContain('compose config --quiet');
        expect($dockerCalls)->toContain('--profile background ps --services --status running');
        expect($dockerCalls)->not->toContain('compose exec');
        expect($dockerCalls)->not->toContain('compose up');
        expect($dockerCalls)->not->toContain('compose restart');
        expect($dockerCalls)->not->toContain('migrate');

        $applyAttempt = new Process(
            [
                'bash',
                base_path('tools/ops/update-safe.sh'),
                '--apply',
            ],
            base_path(),
            [
                'PATH' => $directory.PATH_SEPARATOR.getenv('PATH'),
                'OPS_TEST_DOCKER_LOG' => $dockerLog,
            ],
        );
        $applyAttempt->run();

        expect($applyAttempt->getExitCode())->toBe(1);
        expect($applyAttempt->getErrorOutput())->toContain('--apply requer --backup PATH');

        $allDockerCalls = file_get_contents($dockerLog);
        expect($allDockerCalls)->not->toContain('compose exec');
        expect($allDockerCalls)->not->toContain('compose up');
        expect($allDockerCalls)->not->toContain('compose restart');
    } finally {
        if (is_file($dockerLog)) {
            unlink($dockerLog);
        }
        if (is_file($docker)) {
            unlink($docker);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('keeps the public unsubscribe gateway separate from the signed HubSpot webhook', function () {
    $gateway = file_get_contents(base_path('docker/campaign-unsubscribe/nginx.conf'));
    $routes = file_get_contents(base_path('routes/web.php'));
    $compose = file_get_contents(base_path('compose.yaml'));

    expect($gateway)->not->toBeFalse();
    expect($routes)->not->toBeFalse();
    expect($compose)->not->toBeFalse();

    expect($gateway)->toContain('/email/descadastro/');
    expect($gateway)->toContain('location / { return 404; }');
    expect($gateway)->not->toContain('/webhooks/hubspot');
    expect($routes)->toContain("->middleware(\n        'hubspot.signature'");
    expect($compose)->toContain('campaigns-unsubscribe-gateway:8080');
    expect($compose)->toContain('max-size: \'10m\'');
    expect($compose)->toContain('condition: service_healthy');
});

it('reports operational heartbeat and queue depth without reading job payloads', function () {
    config(['campaign_mail.enabled' => false]);

    Cache::put(
        'ops:scheduler:last-heartbeat',
        now()->toIso8601String(),
        now()->addMinutes(5),
    );

    foreach (HubSpotQueueHeartbeat::QUEUES as $queue) {
        Cache::put(
            HubSpotQueueHeartbeat::cacheKey($queue),
            now()->toIso8601String(),
            now()->addMinutes(5),
        );
    }

    foreach (QueueWorkerHeartbeat::QUEUES as $queue) {
        Cache::put(
            QueueWorkerHeartbeat::cacheKey($queue),
            now()->toIso8601String(),
            now()->addMinutes(5),
        );
    }

    $redisQueue = new class
    {
        public function size(string $queue): int
        {
            return match ($queue) {
                'hubspot-webhooks' => 2,
                default => 0,
            };
        }
    };

    Queue::shouldReceive('connection')
        ->with('redis')
        ->andReturn($redisQueue);

    $this->artisan('ops:health')
        ->expectsOutput('Scheduler: heartbeat recente.')
        ->expectsOutput('Worker hubspot-webhooks: heartbeat recente.')
        ->expectsOutput('Worker default: heartbeat recente.')
        ->expectsOutput('Fila hubspot-webhooks: 2 jobs pendentes.')
        ->assertExitCode(0);
});

it('dispatches operational heartbeats only to the configured worker queues', function () {
    config(['campaign_mail.enabled' => false]);
    Queue::fake();

    $this->artisan('ops:workers-ping')
        ->assertExitCode(0);

    Queue::assertPushedOn('default', QueueWorkerHeartbeat::class);
    Queue::assertPushedOn('hubspot-bulk', QueueWorkerHeartbeat::class);
});

it('records a worker heartbeat in cache without external operations', function () {
    (new QueueWorkerHeartbeat('default'))->handle();

    expect(Cache::get(QueueWorkerHeartbeat::cacheKey('default')))
        ->toBeString();
});

it('allows a heartbeat for the configured campaign queue', function () {
    config(['campaign_mail.queue' => 'campaign-mail-out']);

    expect((new QueueWorkerHeartbeat('campaign-mail-out'))->queueName)
        ->toBe('campaign-mail-out');
});

it('uses bounded daily Laravel logs by default', function () {
    expect(config('logging.channels.daily.max_files'))->toBe(14);
    expect(file_get_contents(base_path('config/logging.php')))
        ->toContain("'default' => env('LOG_CHANNEL', 'daily')");
});
