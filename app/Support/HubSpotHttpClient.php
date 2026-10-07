<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class HubSpotHttpClient
{
    /**
     * Cria um cliente HTTP HubSpot com retry
     * apenas para operações explicitamente
     * consideradas seguras.
     *
     * Por padrão somente GET recebe retry.
     *
     * POST / PATCH / PUT de criação ou alteração
     * NÃO devem ser repetidos automaticamente,
     * pois uma resposta perdida pode esconder
     * uma gravação que já aconteceu remotamente.
     *
     * @param  list<string>  $retryMethods
     */
    public static function make(
        bool $asJson = false,
        array $retryMethods = [
            'GET',
        ],
    ): PendingRequest {
        $token =
            trim(
                (string) config(
                    'services.hubspot.access_token'
                )
            );

        if ($token === '') {
            throw new RuntimeException(
                'Token do HubSpot não configurado.'
            );
        }

        $methods =
            array_values(
                array_unique(
                    array_map(
                        static fn (
                            string $method
                        ): string => mb_strtoupper(
                            trim(
                                $method
                            )
                        ),
                        $retryMethods
                    )
                )
            );

        $request =
            Http::withToken(
                $token
            )
                ->acceptJson()
                ->connectTimeout(
                    5
                )
                ->timeout(
                    30
                );

        if ($asJson) {
            $request =
                $request->asJson();
        }

        $attempts =
            max(
                1,
                (int) config(
                    'services.hubspot.http_retry_attempts',
                    4
                )
            );

        return $request->retry(
            $attempts,

            static function (
                int $attempt,
                mixed $exception,
            ): int {
                return self::retryDelayMilliseconds(
                    attempt: $attempt,

                    exception: $exception,
                );
            },

            static function (
                Throwable $exception,
                PendingRequest $request,
                ?string $method = null,
            ) use (
                $methods
            ): bool {
                unset(
                    $request
                );

                $method =
                    mb_strtoupper(
                        trim(
                            (string) $method
                        )
                    );

                /*
                 * Uma escrita não deve ser
                 * repetida automaticamente.
                 *
                 * Chamadas POST que são apenas
                 * pesquisa/batch-read podem ser
                 * explicitamente habilitadas pelo
                 * chamador.
                 */
                if (
                    $method === ''
                    || ! in_array(
                        $method,
                        $methods,
                        true
                    )
                ) {
                    return false;
                }

                /*
                 * DNS, timeout, conexão recusada,
                 * conexão resetada etc.
                 */
                if (
                    $exception
                    instanceof ConnectionException
                ) {
                    return true;
                }

                if (
                    ! $exception
                    instanceof RequestException
                ) {
                    return false;
                }

                $status =
                    $exception
                        ->response
                        ->status();

                /*
                 * Rate limit.
                 */
                if ($status === 429) {
                    return true;
                }

                /*
                 * Somente erros transitórios
                 * de servidor.
                 *
                 * Não repetimos 400, 401, 403,
                 * 404 ou 422.
                 */
                return in_array(
                    $status,
                    [
                        500,
                        502,
                        503,
                        504,
                    ],
                    true
                );
            },

            throw: false,
        );
    }

    private static function retryDelayMilliseconds(
        int $attempt,
        mixed $exception,
    ): int {
        /*
         * HubSpot pode informar quantos segundos
         * devemos esperar através de Retry-After.
         */
        if (
            $exception
            instanceof RequestException
            && $exception
                ->response
                ->status()
                === 429
        ) {
            $retryAfter =
                trim(
                    (string) $exception
                        ->response
                        ->header(
                            'Retry-After'
                        )
                );

            if (
                $retryAfter !== ''
                && is_numeric(
                    $retryAfter
                )
            ) {
                return self::limitDelay(
                    (int) round(
                        ((float) $retryAfter)
                        * 1000
                    )
                );
            }

            if ($retryAfter !== '') {
                $retryAt =
                    strtotime(
                        $retryAfter
                    );

                if ($retryAt !== false) {
                    return self::limitDelay(
                        max(
                            0,
                            (
                                $retryAt
                                - time()
                            )
                            * 1000
                        )
                    );
                }
            }
        }

        $configured =
            config(
                'services.hubspot.http_retry_delays_ms',
                [
                    250,
                    750,
                    1500,
                ]
            );

        $delays =
            is_array(
                $configured
            )
                ? array_values(
                    array_map(
                        static fn (
                            mixed $delay
                        ): int => max(
                            0,
                            (int) $delay
                        ),
                        $configured
                    )
                )
                : [
                    250,
                    750,
                    1500,
                ];

        if ($delays === []) {
            return 0;
        }

        $index =
            max(
                0,
                $attempt - 1
            );

        $delay =
            $delays[
                $index
            ]
            ?? $delays[
                array_key_last(
                    $delays
                )
            ];

        return self::limitDelay(
            $delay
        );
    }

    private static function limitDelay(
        int $milliseconds
    ): int {
        $maximum =
            max(
                0,
                (int) config(
                    'services.hubspot.http_retry_max_delay_ms',
                    10000
                )
            );

        return max(
            0,
            min(
                $maximum,
                $milliseconds
            )
        );
    }
}
