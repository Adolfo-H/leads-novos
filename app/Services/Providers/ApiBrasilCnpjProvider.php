<?php

namespace App\Services\Providers;

use App\Contracts\CnpjDataProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ApiBrasilCnpjProvider implements CnpjDataProvider
{
    public function name(): string
    {
        return 'apibrasil';
    }

    public function lookup(string $cnpj): array
    {
        $token = config(
            'services.apibrasil.token'
        );

        if (! $token) {
            throw new RuntimeException(
                'APIBrasil não configurada. Informe APIBRASIL_BEARER_TOKEN.'
            );
        }

        $response = Http::baseUrl(
            config(
                'services.apibrasil.base_url'
            )
        )
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->retry(
                2,
                1000,
                throw: false
            )
            ->post(
                '/consulta/cnpj/credits',
                [
                    'tipo' => config(
                        'services.apibrasil.cnpj_type'
                    ),

                    'cnpj' => $cnpj,
                ]
            );

        if ($response->failed()) {
            throw new RuntimeException(
                'Erro APIBrasil: HTTP '
                .$response->status()
            );
        }

        $json = $response->json();

        if (
            ! is_array($json)
        ) {
            throw new RuntimeException(
                'Resposta inválida da APIBrasil.'
            );
        }

        if (
            ($json['error'] ?? false)
            === true
        ) {
            throw new RuntimeException(
                (string) (
                    $json['message']
                    ?? $json['error_message']
                    ?? 'APIBrasil retornou erro.'
                )
            );
        }

        /*
         * A API atualmente aparece documentada
         * tanto com "response" quanto, via SDK,
         * com retorno dentro de "data".
         */
        $data =
            $json['response']
            ?? $json['data']
            ?? $json;

        if (! is_array($data)) {
            throw new RuntimeException(
                'APIBrasil não retornou dados do CNPJ.'
            );
        }

        return $data;
    }
}
