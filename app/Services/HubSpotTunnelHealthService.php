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
    public function snapshot(): array
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

        $cacheSeconds =
            max(
                10,
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
}
