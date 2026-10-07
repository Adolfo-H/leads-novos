<?php

namespace App\Services;

use App\Models\User;
use App\Support\HubSpotHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;

final class HubSpotOwnerResolverService
{
    public function resolve(
        User $user,
        bool $refresh = false,
    ): string {
        $storedOwnerId =
            trim(
                (string) $user->hubspot_owner_id
            );

        /*
         * Depois que o vínculo foi descoberto,
         * usamos o ID salvo localmente.
         *
         * Isso evita uma chamada ao HubSpot
         * a cada criação de oportunidade.
         */
        if (
            ! $refresh
            && $storedOwnerId !== ''
        ) {
            return $storedOwnerId;
        }

        $email =
            mb_strtolower(
                trim(
                    (string) $user->email
                )
            );

        if (
            $email === ''
            || filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            throw new RuntimeException(
                'O usuário não possui um e-mail válido '
                .'para localizar o responsável no HubSpot.'
            );
        }

        $response =
            $this->client()
                ->get(
                    $this->baseUrl()
                    .'/crm/v3/owners',
                    [
                        'email' => $email,

                        /*
                         * Não queremos atribuir negócio
                         * ou tarefa a usuário arquivado.
                         */
                        'archived' => 'false',

                        'limit' => 100,
                    ]
                );

        $this->ensureSuccess(
            $response
        );

        $data =
            $response->json();

        if (! is_array($data)) {
            throw new RuntimeException(
                'O HubSpot retornou uma resposta inválida '
                .'ao consultar responsáveis.'
            );
        }

        $results =
            $data['results']
            ?? [];

        if (! is_array($results)) {
            $results = [];
        }

        $matches = [];

        foreach ($results as $owner) {
            if (! is_array($owner)) {
                continue;
            }

            $ownerEmail =
                mb_strtolower(
                    trim(
                        (string) (
                            $owner['email']
                            ?? ''
                        )
                    )
                );

            /*
             * Mesmo usando ?email=, fazemos
             * comparação exata para nunca
             * vincular o usuário errado.
             */
            if ($ownerEmail !== $email) {
                continue;
            }

            $archived =
                (bool) (
                    $owner['archived']
                    ?? false
                );

            if ($archived) {
                continue;
            }

            $ownerId =
                $owner['id']
                ?? null;

            if (
                ! is_scalar($ownerId)
                || trim(
                    (string) $ownerId
                ) === ''
            ) {
                continue;
            }

            $matches[] =
                trim(
                    (string) $ownerId
                );
        }

        $matches =
            array_values(
                array_unique(
                    $matches
                )
            );

        if ($matches === []) {
            throw new RuntimeException(
                'Nenhum responsável ativo do HubSpot foi '
                .'encontrado para o e-mail '
                .$email
                .'.'
            );
        }

        if (count($matches) > 1) {
            throw new RuntimeException(
                'Mais de um responsável do HubSpot foi '
                .'encontrado para o mesmo e-mail. '
                .'A criação foi interrompida por segurança.'
            );
        }

        $ownerId =
            $matches[0];

        /*
         * Salvamos o Owner ID do HubSpot,
         * não o userId.
         */
        $user->forceFill([
            'hubspot_owner_id' => $ownerId,
        ])->save();

        return $ownerId;
    }

    private function baseUrl(): string
    {
        $baseUrl =
            rtrim(
                (string) config(
                    'services.hubspot.base_url'
                ),
                '/'
            );

        if ($baseUrl === '') {
            throw new RuntimeException(
                'HUBSPOT_BASE_URL não configurada.'
            );
        }

        return $baseUrl;
    }

    private function client(): PendingRequest
    {
        /*
         * GETs podem ser repetidos em falhas
         * transitórias.
         *
         * POST/PATCH/PUT continuam sem retry
         * automático para não duplicar objetos
         * no HubSpot.
         */
        return HubSpotHttpClient::make(
            asJson: true,
        );
    }

    private function ensureSuccess(
        Response $response,
    ): void {
        if (! $response->failed()) {
            return;
        }

        throw new RuntimeException(
            'Erro ao localizar o responsável no HubSpot. '
            .'HTTP '
            .$response->status()
            .': '
            .mb_substr(
                $response->body(),
                0,
                1000
            )
        );
    }
}
