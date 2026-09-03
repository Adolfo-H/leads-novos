<?php

namespace App\Services\Providers;

use App\Contracts\CnpjDataProvider;
use App\Exceptions\CnpjNotFoundException;
use App\Exceptions\CnpjProviderTemporaryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class BrasilApiCnpjProvider implements CnpjDataProvider
{
    public function name(): string
    {
        return 'brasilapi';
    }

    /** @return array<string, mixed> */
    public function lookup(string $cnpj): array
    {
        $cnpj = preg_replace(
            '/\D/',
            '',
            $cnpj
        );

        if (
            ! is_string($cnpj)
            || strlen($cnpj) !== 14
        ) {
            throw new RuntimeException(
                'CNPJ inválido para consulta.'
            );
        }

        try {
            $response = Http::baseUrl(
                rtrim(
                    (string) config(
                        'services.brasilapi.base_url'
                    ),
                    '/'
                )
            )
                ->acceptJson()
                ->timeout(20)
                ->get(
                    'cnpj/v1/'.$cnpj
                );
        } catch (ConnectionException $exception) {
            throw new CnpjProviderTemporaryException(
                'Não foi possível conectar à BrasilAPI.',
                previous: $exception
            );
        }

        if ($response->status() === 404) {
            throw new CnpjNotFoundException(
                'CNPJ não encontrado na BrasilAPI.'
            );
        }

        if ($response->status() === 429) {
            throw new CnpjProviderTemporaryException(
                'BrasilAPI limitou temporariamente as consultas. O CNPJ será tentado novamente.'
            );
        }

        if ($response->serverError()) {
            throw new CnpjProviderTemporaryException(
                'BrasilAPI temporariamente indisponível. HTTP '
                .$response->status()
            );
        }

        if ($response->failed()) {
            $message = $response->json(
                'message'
            );

            throw new RuntimeException(
                $message
                    ? 'BrasilAPI: '.$message
                    : 'Erro BrasilAPI: HTTP '
                        .$response->status()
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException(
                'Resposta inválida da BrasilAPI.'
            );
        }

        if (
            empty(
                $data['razao_social']
            )
        ) {
            throw new RuntimeException(
                'BrasilAPI não retornou razão social.'
            );
        }

        return $data;
    }
}
