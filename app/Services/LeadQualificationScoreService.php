<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyExportIntelligence;

final class LeadQualificationScoreService
{
    public const VERSION = 'v3';

    public function __construct(
        private readonly LeadOperationalClassificationService $classification,
    ) {}

    /**
     * @return array{
     *     score: int,
     *     priority: string,
     *     label: string,
     *     commercial_status: string,
     *     work_status: string,
     *     icp_raw_score: int,
     *     research_complete: bool,
     *     factors: list<array{
     *         key: string,
     *         label: string,
     *         points: int,
     *         max_points: int,
     *         detail: string
     *     }>
     * }
     */
    public function calculate(
        Company $company
    ): array {
        $company->loadMissing([
            'icpScore',
            'crmCheck',
            'exportIntelligence',
        ]);

        $crm =
            $company->crmCheck;

        $commercialStatus =
            $this->classification
                ->commercialStatus(
                    $crm
                );

        $openTasks =
            $this->classification
                ->openTasks(
                    $crm
                );

        $workStatus =
            $this->classification
                ->workStatus(
                    $crm,
                    $openTasks
                );

        /*
         * =================================================
         * ICP REAL
         * =================================================
         *
         * O ICP já possui score 0..100.
         *
         * Em vez de transformar A em 30,
         * B em 22 e C em 10, preservamos
         * a diferença real entre empresas.
         *
         * Peso final: 60%.
         */
        $rawIcpValue =
            data_get(
                $company,
                'icpScore.score'
            );

        $rawIcpScore =
            is_numeric(
                $rawIcpValue
            )
                ? max(
                    0,
                    min(
                        100,
                        (int) $rawIcpValue
                    )
                )
                : 0;

        $icpPoints =
            (int) round(
                $rawIcpScore
                * 0.60
            );

        $grade =
            trim(
                (string) (
                    data_get(
                        $company,
                        'icpScore.grade'
                    )
                    ?? ''
                )
            );

        /*
         * =================================================
         * SITUAÇÃO COMERCIAL
         * =================================================
         *
         * Novo          15
         * Conhecido     10
         * Reprospecção  12
         * Cliente        0
         */
        $commercialPoints =
            match (
                $commercialStatus
            ) {
                'new' => 15,

                'reprospecting' => 12,

                'known' => 10,

                default => 0,
            };

        /*
         * =================================================
         * ACOMPANHAMENTO
         * =================================================
         *
         * Tarefa pendente recebe maior peso
         * porque existe uma ação concreta.
         */
        $followUpPoints =
            match (
                $workStatus
            ) {
                'waiting' => 15,

                'contacting' => 12,

                'reprospecting' => 10,

                'future' => 8,

                'new' => 5,

                default => 0,
            };

        /*
         * =================================================
         * EXPORTAÇÃO
         * =================================================
         *
         * Mantemos como bônus.
         *
         * O score deixa de depender da pesquisa
         * de exportação para sair dos 30 pontos.
         */
        $direct =
            $this->exportDimension(
                $company
                    ->exportIntelligence,
                'direct',
                'Exportação direta',
                4,
            );

        $indirect =
            $this->exportDimension(
                $company
                    ->exportIntelligence,
                'indirect',
                'Exportação indireta',
                4,
            );

        $trading =
            $this->exportDimension(
                $company
                    ->exportIntelligence,
                'trading',
                'Relação com trading',
                2,
            );

        $score =
            min(
                100,
                $icpPoints
                + $commercialPoints
                + $followUpPoints
                + $direct['points']
                + $indirect['points']
                + $trading['points']
            );

        /*
         * Mantemos 4 níveis internamente por
         * compatibilidade.
         *
         * O front pode depois apresentar de
         * forma simplificada.
         */
        $priority =
            match (true) {
                $score >= 75 => 'high',

                $score >= 55 => 'medium',

                default => 'low',
            };

        $label =
            match ($priority) {
                'high' => 'Prioridade alta',

                'medium' => 'Prioridade média',

                default => 'Prioridade baixa',
            };

        $researchComplete =

            ! $direct[
                'not_researched'
            ]
            && ! $indirect[
                'not_researched'
            ]
            && ! $trading[
                'not_researched'
            ];

        return [
            'score' => $score,

            'priority' => $priority,

            'label' => $label,

            'commercial_status' => $commercialStatus,

            'work_status' => $workStatus,

            'icp_raw_score' => $rawIcpScore,

            'research_complete' => $researchComplete,

            'factors' => [
                $this->factor(
                    key: 'icp',
                    label: 'Perfil ICP',
                    points: $icpPoints,
                    maxPoints: 60,
                    detail: (
                        $grade !== ''
                            ? 'ICP '.$grade.' · '
                            : 'ICP · '
                    )
                        .$rawIcpScore
                        .'/100',
                ),

                $this->factor(
                    key: 'commercial_status',
                    label: 'Situação comercial',
                    points: $commercialPoints,
                    maxPoints: 15,
                    detail: $this->commercialLabel(
                        $commercialStatus
                    ),
                ),

                $this->factor(
                    key: 'follow_up',
                    label: 'Acompanhamento',
                    points: $followUpPoints,
                    maxPoints: 15,
                    detail: $this->workLabel(
                        $workStatus
                    ),
                ),

                $direct[
                    'factor'
                ],

                $indirect[
                    'factor'
                ],

                $trading[
                    'factor'
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     points: int,
     *     not_researched: bool,
     *     factor: array{
     *         key: string,
     *         label: string,
     *         points: int,
     *         max_points: int,
     *         detail: string
     *     }
     * }
     */
    private function exportDimension(
        ?CompanyExportIntelligence $intelligence,
        string $dimension,
        string $label,
        int $maximum,
    ): array {
        if ($intelligence === null) {
            return [
                'points' => 0,

                'not_researched' => true,

                'factor' => $this->factor(
                    key: $dimension,
                    label: $label,
                    points: 0,
                    maxPoints: $maximum,
                    detail: 'Não pesquisada',
                ),
            ];
        }

        $status =
            trim(
                (string) (
                    $intelligence
                        ->getAttribute(
                            $dimension
                            .'_status'
                        )
                    ?? ''
                )
            );

        $confidence =
            is_numeric(
                $intelligence
                    ->getAttribute(
                        $dimension
                        .'_confidence'
                    )
            )
                ? max(
                    0,
                    min(
                        100,
                        (int)
                        $intelligence
                            ->getAttribute(
                                $dimension
                                .'_confidence'
                            )
                    )
                )
                : 0;

        $points =
            $status === 'yes'
                ? (int) round(
                    $maximum
                    * (
                        $confidence
                        / 100
                    )
                )
                : 0;

        return [
            'points' => $points,

            'not_researched' => $status === ''
                || $status
                    === 'not_researched',

            'factor' => $this->factor(
                key: $dimension,
                label: $label,
                points: $points,
                maxPoints: $maximum,
                detail: match ($status) {
                    'yes' => 'Sim · '
                        .$confidence
                        .'% de confiança',

                    'no' => 'Não',

                    'uncertain' => 'Sem comprovação suficiente',

                    default => 'Não pesquisada',
                },
            ),
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     points: int,
     *     max_points: int,
     *     detail: string
     * }
     */
    private function factor(
        string $key,
        string $label,
        int $points,
        int $maxPoints,
        string $detail,
    ): array {
        return [
            'key' => $key,

            'label' => $label,

            'points' => $points,

            'max_points' => $maxPoints,

            'detail' => $detail,
        ];
    }

    private function commercialLabel(
        string $status
    ): string {
        return match ($status) {
            'new' => 'Novo',

            'client' => 'Cliente',

            'reprospecting' => 'Reprospecção',

            default => 'Conhecido',
        };
    }

    private function workLabel(
        string $status
    ): string {
        return match ($status) {
            'waiting' => 'Aguardando retorno',

            'contacting' => 'Em contato',

            'future' => 'Oportunidade futura',

            'reprospecting' => 'Reprospecção',

            default => 'Novo',
        };
    }
}
