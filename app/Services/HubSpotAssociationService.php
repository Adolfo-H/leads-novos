<?php

namespace App\Services;

use App\Support\HubSpotHttpClient;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use RuntimeException;

final class HubSpotAssociationService
{
    /**
     * Cria uma associação default entre
     * dois objetos CRM do HubSpot.
     *
     * Exemplos:
     *
     * companies -> contacts
     * contacts  -> deals
     * tasks     -> contacts
     *
     * PUT é operação de escrita e, por
     * segurança, não recebe retry automático.
     */
    public function associate(
        string $fromType,
        string $fromId,
        string $toType,
        string $toId,
    ): void {
        $fromType =
            $this->required(
                $fromType,
                'tipo de origem'
            );

        $fromId =
            $this->required(
                $fromId,
                'ID de origem'
            );

        $toType =
            $this->required(
                $toType,
                'tipo de destino'
            );

        $toId =
            $this->required(
                $toId,
                'ID de destino'
            );

        $response =
            HubSpotHttpClient::make(
                asJson: true,
            )
                ->put(
                    $this->baseUrl()
                    .'/crm/v4/objects/'
                    .rawurlencode(
                        $fromType
                    )
                    .'/'
                    .rawurlencode(
                        $fromId
                    )
                    .'/associations/default/'
                    .rawurlencode(
                        $toType
                    )
                    .'/'
                    .rawurlencode(
                        $toId
                    )
                );

        $this->ensureSuccess(
            $response,
            $fromType,
            $toType,
        );
    }

    private function required(
        string $value,
        string $field,
    ): string {
        $value =
            trim(
                $value
            );

        if ($value === '') {
            throw new InvalidArgumentException(
                'HubSpot: '
                .$field
                .' não informado para associação.'
            );
        }

        return $value;
    }

    private function baseUrl(): string
    {
        $url =
            rtrim(
                (string) config(
                    'services.hubspot.base_url'
                ),
                '/'
            );

        if ($url === '') {
            throw new RuntimeException(
                'HUBSPOT_BASE_URL não configurada.'
            );
        }

        return $url;
    }

    private function ensureSuccess(
        Response $response,
        string $fromType,
        string $toType,
    ): void {
        if (! $response->failed()) {
            return;
        }

        throw new RuntimeException(
            'Erro ao associar '
            .$fromType
            .' com '
            .$toType
            .' no HubSpot. HTTP '
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
