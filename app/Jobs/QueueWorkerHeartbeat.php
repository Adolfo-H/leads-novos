<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class QueueWorkerHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * @var list<string>
     */
    public const QUEUES = [
        'default',
        'hubspot-bulk',
    ];

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 90;

    public function __construct(
        public string $queueName,
    ) {
        $allowedQueues = [
            ...self::QUEUES,
            (string) config('campaign_mail.queue', 'campaigns'),
        ];

        if (! in_array($queueName, $allowedQueues, true)) {
            throw new InvalidArgumentException(
                'Fila inválida para heartbeat operacional: '.$queueName
            );
        }

        $this->onConnection('redis');
        $this->onQueue($queueName);
    }

    public function uniqueId(): string
    {
        return $this->queueName;
    }

    public function handle(): void
    {
        Cache::put(
            self::cacheKey($this->queueName),
            now()->toIso8601String(),
            now()->addMinutes(5),
        );
    }

    public static function cacheKey(string $queueName): string
    {
        return 'ops:worker-heartbeat:'.$queueName;
    }
}
