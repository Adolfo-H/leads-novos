<?php

namespace App\Services\Providers;

use App\Contracts\CnpjGroupDataProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ReceitaLocalCnpjGroupProvider implements CnpjGroupDataProvider
{
    public function name(): string
    {
        return 'receita-local';
    }

    /**
     * @return array{
     *     company: array<string, mixed>,
     *     establishments: list<array{
     *         establishment: array<string, mixed>,
     *         cnaes: list<array{
     *             code: string,
     *             description: string|null,
     *             is_primary: bool
     *         }>
     *     }>
     * }
     */
    public function lookupRoot(
        string $cnpjRoot
    ): array {
        $baseUrl = rtrim(
            (string) config(
                'services.receita_local.base_url'
            ),
            '/'
        );

        try {
            $response = Http::acceptJson()
                ->timeout(30)
                ->get(
                    $baseUrl
                    .'/groups/'
                    .rawurlencode($cnpjRoot)
                );
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Não foi possível conectar ao serviço local da Receita.',
                previous: $exception,
            );
        }

        if ($response->status() === 404) {
            throw new RuntimeException(
                'Empresa não encontrada na base local da Receita.'
            );
        }

        if ($response->status() === 503) {
            throw new RuntimeException(
                'A base local da Receita ainda não está disponível.'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'Erro ao consultar serviço local da Receita. HTTP '
                .$response->status()
                .'.'
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException(
                'O serviço local da Receita retornou uma resposta inválida.'
            );
        }

        $company = $data['company'] ?? null;

        $establishments =
            $data['establishments']
            ?? null;

        if (
            ! is_array($company)
            || ! is_array($establishments)
        ) {
            throw new RuntimeException(
                'O serviço local da Receita retornou uma estrutura inválida.'
            );
        }

        /**
         * A API receita-data segue o contrato
         * CnpjGroupDataProvider.
         *
         * @var array{
         *     company: array<string, mixed>,
         *     establishments: list<array{
         *         establishment: array<string, mixed>,
         *         cnaes: list<array{
         *             code: string,
         *             description: string|null,
         *             is_primary: bool
         *         }>
         *     }>
         * } $data
         */
        return $data;
    }
}
