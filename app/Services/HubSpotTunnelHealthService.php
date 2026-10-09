<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class HubSpotTunnelHealthService
{
    /**
     * @return array{
     *     configured: bool,
     *     checked: bool,
     *     online: bool|null,
     *     label: string,
     *     http_status: int|null,
     *     checked_at: string|null
     * }
     */
    public function snapshot(bool $probeIfMissing = false): array
    {
        $url =
            trim(
                (string) config(
                    'services.hubspot.webhook_public_url'
                )
            );

        if ($url === '') {
            return [
                'configured' => false,
                'checked' => false,
                'online' => null,
                'label' => 'não configurado',
                'http_status' => null,
                'checked_at' => null,
            ];
        }

        $enabled =
            (bool) config(
                'services.hubspot.health_tunnel_check_enabled',
                true
            );

        if (! $enabled) {
            return [
                'configured' => true,
                'checked' => false,
                'online' => null,
                'label' => 'verificação desativada',
                'http_status' => null,
                'checked_at' => null,
            ];
        }

        /*
         * WEB_HEALTH_NONBLOCKING_V24103:
         * Nunca testar a URL publica no GET /leads ou Livewire.
         * Cache ausente significa "verificacao pendente", nao "offline".
         * A sondagem real e executada pelo scheduler via refresh().
         */
        if (! $probeIfMissing) {
            $key = 'hubspot:tunnel-health:'.hash('sha256', $url);
            $cached = Cache::get($key);

            if (
                is_array($cached)
                && array_key_exists('configured', $cached)
                && array_key_exists('checked', $cached)
                && array_key_exists('online', $cached)
                && array_key_exists('label', $cached)
            ) {
                return [
                    'configured' => (bool) $cached['configured'],
                    'checked' => (bool) $cached['checked'],
                    'online' => is_bool($cached['online']) ? $cached['online'] : null,
                    'label' => (string) $cached['label'],
                    'http_status' => is_int($cached['http_status'] ?? null) ? $cached['http_status'] : null,
                    'checked_at' => is_string($cached['checked_at'] ?? null) ? $cached['checked_at'] : null,
                ];
            }

            return [
                'configured' => true,
                'checked' => false,
                'online' => null,
                'label' => 'verificação agendada',
                'http_status' => null,
                'checked_at' => null,
            ];
        }

        // O scheduler executa a cada minuto: conservar por ao menos 2 min.
        $cacheSeconds =
            max(
                120,
                (int) config(
                    'services.hubspot.health_tunnel_cache_seconds',
                    30
                )
            );

        $key =
            'hubspot:tunnel-health:'
            .hash(
                'sha256',
                $url
            );

        /**
         * @var array{
         *     configured: bool,
         *     checked: bool,
         *     online: bool|null,
         *     label: string,
         *     http_status: int|null,
         *     checked_at: string|null
         * } $result
         */
        $result =
            Cache::remember(
                $key,
                now()
                    ->addSeconds(
                        $cacheSeconds
                    ),
                function () use (
                    $url
                ): array {
                    try {
                        $response =
                            Http::connectTimeout(
                                2
                            )
                                ->timeout(
                                    max(
                                        2,
                                        (int) config(
                                            'services.hubspot.health_tunnel_timeout_seconds',
                                            4
                                        )
                                    )
                                )
                                ->withHeaders([
                                    'User-Agent' => 'ExportControl-Prospector-Health/1.0',
                                ])
                                ->head(
                                    $url
                                );

                        $status =
                            $response
                                ->status();

                        /*
                         * O webhook aceita POST.
                         *
                         * Portanto HEAD normalmente
                         * recebe HTTP 405.
                         *
                         * Isso é POSITIVO: prova que:
                         *
                         * - DNS resolveu;
                         * - HTTPS respondeu;
                         * - ngrok/túnel respondeu;
                         * - Laravel recebeu a URL.
                         */
                        $online =
                            $status >= 200
                            && $status < 500
                            && $status !== 404;

                        if ($online) {
                            $label =
                                'online';

                        } elseif ($status === 404) {
                            $label =
                                'endpoint não encontrado';

                        } else {
                            $label =
                                'indisponível · HTTP '
                                .$status;
                        }

                        return [
                            'configured' => true,
                            'checked' => true,
                            'online' => $online,
                            'label' => $label,
                            'http_status' => $status,
                            'checked_at' => now()
                                ->toIso8601String(),
                        ];

                    } catch (
                        ConnectionException
                    ) {
                        return [
                            'configured' => true,
                            'checked' => true,
                            'online' => false,
                            'label' => 'sem resposta',
                            'http_status' => null,
                            'checked_at' => now()
                                ->toIso8601String(),
                        ];
                    }
                }
            );

        return $result;
    }

    /**
     * Sondagem explicita para linha de comando/scheduler (fora do HTTP).
     * Invalida apenas o resultado do proprio monitor de tunel, jamais filas.
     *
     * @return array{configured:bool,checked:bool,online:bool|null,label:string,http_status:int|null,checked_at:string|null}
     */
    public function refresh(): array
    {
        $url = trim((string) config('services.hubspot.webhook_public_url'));

        if (
            $url !== ''
            && (bool) config('services.hubspot.health_tunnel_check_enabled', true)
        ) {
            Cache::forget('hubspot:tunnel-health:'.hash('sha256', $url));
        }

        return $this->snapshot(probeIfMissing: true);
    }
}
