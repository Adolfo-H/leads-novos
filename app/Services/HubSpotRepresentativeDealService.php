<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Throwable;

final class HubSpotRepresentativeDealService
{
    /**
     * Escolhe somente o negócio usado como
     * referência operacional na tela.
     *
     * TODOS os negócios continuam preservados
     * no CRM/metadata.
     *
     * Prioridade:
     *
     * 1. negócio aberto e ativo;
     * 2. oportunidade futura aberta;
     * 3. negócio ganho;
     * 4. demais negócios encerrados.
     *
     * Em empate, vence o registro alterado
     * mais recentemente no HubSpot.
     *
     * @param  list<array<string, mixed>>  $deals
     * @return array<string, mixed>
     */
    public function select(
        array $deals
    ): array {
        if ($deals === []) {
            return [];
        }

        usort(
            $deals,
            function (
                array $left,
                array $right
            ): int {
                $leftRank =
                    $this->rank(
                        $left
                    );

                $rightRank =
                    $this->rank(
                        $right
                    );

                if (
                    $leftRank
                    !== $rightRank
                ) {
                    return $leftRank
                        <=>
                        $rightRank;
                }

                $leftDate =
                    $this->timestamp(
                        $left[
                            'updated_at'
                        ]
                        ?? $left[
                            'closed_at'
                        ]
                        ?? null
                    );

                $rightDate =
                    $this->timestamp(
                        $right[
                            'updated_at'
                        ]
                        ?? $right[
                            'closed_at'
                        ]
                        ?? null
                    );

                if (
                    $leftDate
                    !== $rightDate
                ) {
                    return $rightDate
                        <=>
                        $leftDate;
                }

                return strcmp(
                    (string) (
                        $left[
                            'id'
                        ]
                        ?? ''
                    ),
                    (string) (
                        $right[
                            'id'
                        ]
                        ?? ''
                    )
                );
            }
        );

        return $deals[0];
    }

    /**
     * @param  array<string, mixed>  $deal
     */
    private function rank(
        array $deal
    ): int {
        $closed =
            $this->boolean(
                $deal[
                    'is_closed'
                ]
                ?? false
            );

        $won =
            $this->boolean(
                $deal[
                    'is_closed_won'
                ]
                ?? false
            );

        $stage =
            trim(
                (string) (
                    $deal[
                        'stage_id'
                    ]
                    ?? ''
                )
            );

        if (! $closed) {
            if (
                $stage !== ''
                && in_array(
                    $stage,
                    $this->futureStages(),
                    true
                )
            ) {
                /*
                 * Future é um negócio aberto,
                 * porém estacionado para depois.
                 *
                 * Não pode substituir uma venda
                 * ativa em proposta/reunião/etc.
                 */
                return 1;
            }

            return 0;
        }

        if ($won) {
            return 2;
        }

        return 3;
    }

    /**
     * @return list<string>
     */
    private function futureStages(): array
    {
        $raw =
            config(
                'services.hubspot.lead_future_stages',
                []
            );

        if (! is_array($raw)) {
            return [];
        }

        $result = [];

        foreach ($raw as $stage) {
            if (! is_scalar($stage)) {
                continue;
            }

            $stage =
                trim(
                    (string) $stage
                );

            if ($stage !== '') {
                $result[] =
                    $stage;
            }
        }

        return array_values(
            array_unique(
                $result
            )
        );
    }

    private function timestamp(
        mixed $value
    ): int {
        if (
            ! is_scalar($value)
            || trim(
                (string) $value
            ) === ''
        ) {
            return 0;
        }

        try {
            return CarbonImmutable::parse(
                (string) $value
            )->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }

    private function boolean(
        mixed $value
    ): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(
            mb_strtolower(
                trim(
                    $value
                )
            ),
            [
                'true',
                '1',
                'yes',
                'sim',
            ],
            true
        );
    }
}
