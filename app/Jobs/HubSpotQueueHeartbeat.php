<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class HubSpotQueueHeartbeat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Filas prioritárias que precisam estar
     * permanentemente disponíveis.
     *
     * @var list<string>
     */
    public const QUEUES = [
        'hubspot-webhooks',
        'hubspot-realtime',
    ];

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 90;

    public function __construct(
        public string $queueName,
    ) {
        if (
            ! in_array(
                $queueName,
                self::QUEUES,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Fila HubSpot inválida para heartbeat: '
                .$queueName
            );
        }

        $this->onConnection(
            'redis'
        );

        /*
         * O heartbeat entra na própria fila.
         *
         * Se o worker daquela fila morrer,
         * ele simplesmente deixa de atualizar.
         */
        $this->onQueue(
            $queueName
        );
    }

    public function uniqueId(): string
    {
        return $this->queueName;
    }

    public function handle(): void
    {
        Cache::put(
            self::cacheKey(
                $this->queueName
            ),
            now()
                ->toIso8601String(),
            now()
                ->addMinutes(10),
        );
    }

    public static function cacheKey(
        string $queueName
    ): string {
        return
            'hubspot:worker-heartbeat:'
            .$queueName;
    }

    public static function dispatchCacheKey(): string
    {
        return 'hubspot:health:last-dispatch';
    }
}
