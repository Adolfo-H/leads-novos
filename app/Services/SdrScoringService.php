<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyExportIntelligence;
use App\Models\CompanySdrScore;

final class SdrScoringService
{
    private const VERSION = 'v2';

    /**
     * @var list<string>
     */
    private const BLOCKED_CRM_STATUSES = [
        'client',
        'opportunity',
    ];

    public function __construct(
        private readonly CrmReprospectingPolicyService $reprospecting,
    ) {}

    public function recalculate(
        Company $company
    ): CompanySdrScore {
        $company->loadMissing([
            'icpScore',
            'crmCheck',
            'exportIntelligence',
        ]);

        $crm =
            $company->crmCheck;

        $crmStatus =
            $this->stringValue(
                $crm
                    ?->getAttribute('status')
            );

        $reprospecting =
            null;

        if (
            $crmStatus !== null
            && in_array(
                $crmStatus,
                self::BLOCKED_CRM_STATUSES,
                true
            )
        ) {
            return $this->storeBlocked(
                company: $company,
                crmStatus: $crmStatus,
            );
        }

        /*
         * Prospectado não é bloqueio eterno.
         *
         * Se o período de carência terminou,
         * a empresa volta a poder receber Score.
         */
        if (
            $crmStatus === 'prospected'
            && $crm !== null
        ) {
            $reprospecting =
                $this->reprospecting
                    ->evaluate(
                        $crm
                    );

            if (
                ! $reprospecting[
                    'eligible'
                ]
            ) {
                return $this->storeBlocked(
                    company: $company,
                    crmStatus: 'prospected',
                    reason: $reprospecting[
                        'message'
                    ],
                    extraMetadata: [
                        'reprospecting' => $reprospecting,
                    ],
                );
            }
        }

        $factors = [];

        /*
         * ICP
         *
         * A = 30
         * B = 22
         * C = 10
         * D = 0
         */
        $grade =
            $this->stringValue(
                $company
                    ->icpScore
                    ?->getAttribute('grade')
            );

        $icpPoints =
            match ($grade) {
                'A' => 30,
                'B' => 22,
                'C' => 10,
                default => 0,
            };

        $factors[] =
            $this->factor(
                key: 'icp',
                label: 'Perfil ICP',
                points: $icpPoints,
                maxPoints: 30,
                detail: $grade
                        ? 'ICP '.$grade
                        : 'ICP não calculado',
            );

        /*
         * CRM
         *
         * Não encontrado = ótima oportunidade
         * Conhecido = já existe registro, mas
         * ainda não bloqueia prospecção.
         */
        $crmPoints =
            match ($crmStatus) {
                'not_found' => 10,
                'known' => 5,
                'prospected' => 0,
                default => 0,
            };

        $factors[] =
            $this->factor(
                key: 'crm',
                label: 'Disponibilidade comercial',
                points: $crmPoints,
                maxPoints: 10,
                detail: match ($crmStatus) {
                    'not_found' => 'Empresa nova no CRM',

                    'known' => 'Empresa conhecida no CRM',

                    'prospected' => 'Reprospecção liberada',

                    null => 'CRM não verificado',

                    default => 'Sem bônus comercial',
                },
            );

        $export =
            $company->exportIntelligence;

        $direct =
            $this->dimensionScore(
                intelligence: $export,
                dimension: 'direct',
                label: 'Exportação direta',
                maxYes: 25,
            );

        $indirect =
            $this->dimensionScore(
                intelligence: $export,
                dimension: 'indirect',
                label: 'Exportação indireta',
                maxYes: 25,
            );

        $trading =
            $this->dimensionScore(
                intelligence: $export,
                dimension: 'trading',
                label: 'Relação com trading',
                maxYes: 10,
            );

        $factors[] =
            $direct['factor'];

        $factors[] =
            $indirect['factor'];

        $factors[] =
            $trading['factor'];

        $score =
            min(
                100,
                $icpPoints
                + $crmPoints
                + $direct['points']
                + $indirect['points']
                + $trading['points']
            );

        $priority =
            match (true) {
                $score >= 85 => 'very_high',

                $score >= 70 => 'high',

                $score >= 50 => 'medium',

                default => 'low',
            };

        $label =
            match ($priority) {
                'very_high' => 'Prioridade muito alta',

                'high' => 'Prioridade alta',

                'medium' => 'Prioridade média',

                default => 'Prioridade baixa',
            };

        $researchComplete =
            ! $direct['not_researched']
            && ! $indirect['not_researched']
            && ! $trading['not_researched'];

        $isProvisional =
            $grade === null
            || $crmStatus === null
            || ! $researchComplete;

        return CompanySdrScore::query()
            ->updateOrCreate(
                [
                    'company_id' => $company->id,
                ],
                [
                    'score' => $score,

                    'priority' => $priority,

                    'label' => $label,

                    'is_eligible' => true,

                    'is_provisional' => $isProvisional,

                    'blocked_reason' => null,

                    'factors' => $factors,

                    'version' => self::VERSION,

                    'metadata' => [
                        'research_complete' => $researchComplete,

                        'crm_status' => $crmStatus,

                        'icp_grade' => $grade,

                        'reprospecting' => $reprospecting,
                    ],

                    'calculated_at' => now(),
                ]
            );
    }

    /**
     * @param  array<string, mixed>  $extraMetadata
     */
    private function storeBlocked(
        Company $company,
        string $crmStatus,
        ?string $reason = null,
        array $extraMetadata = [],
    ): CompanySdrScore {
        $reason ??=
            match ($crmStatus) {
                'client' => 'Empresa já é cliente',

                'opportunity' => 'Empresa já possui oportunidade',

                'prospected' => 'Empresa já foi prospectada',

                default => 'Empresa não elegível',
            };

        return CompanySdrScore::query()
            ->updateOrCreate(
                [
                    'company_id' => $company->id,
                ],
                [
                    'score' => 0,

                    'priority' => 'blocked',

                    'label' => 'Não priorizar',

                    'is_eligible' => false,

                    'is_provisional' => false,

                    'blocked_reason' => $reason,

                    'factors' => [
                        $this->factor(
                            key: 'crm_block',
                            label: 'Bloqueio comercial',
                            points: 0,
                            maxPoints: 0,
                            detail: $reason,
                        ),
                    ],

                    'version' => self::VERSION,

                    'metadata' => array_merge(
                        [
                            'crm_status' => $crmStatus,
                        ],
                        $extraMetadata
                    ),

                    'calculated_at' => now(),
                ]
            );
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
    private function dimensionScore(
        ?CompanyExportIntelligence $intelligence,
        string $dimension,
        string $label,
        int $maxYes,
    ): array {
        if (! $intelligence) {
            return [
                'points' => 0,

                'not_researched' => true,

                'factor' => $this->factor(
                    key: $dimension,
                    label: $label,
                    points: 0,
                    maxPoints: $maxYes,
                    detail: 'Não pesquisada',
                ),
            ];
        }

        $status =
            $this->stringValue(
                $intelligence
                    ->getAttribute(
                        $dimension.'_status'
                    )
            )
            ?? 'not_researched';

        $rawConfidence =
            $intelligence
                ->getAttribute(
                    $dimension.'_confidence'
                );

        $confidence =
            is_numeric($rawConfidence)
                ? max(
                    0,
                    min(
                        100,
                        (int) $rawConfidence
                    )
                )
                : 0;

        $points =
            match ($status) {
                'yes' => (int) round(
                    $maxYes
                    * (
                        $confidence
                        / 100
                    )
                ),

                'uncertain' => 0,

                default => 0,
            };

        $detail =
            match ($status) {
                'yes' => 'Sim · '
                    .$confidence
                    .'% de confiança',

                'no' => 'Não · '
                    .$confidence
                    .'% de confiança',

                'uncertain' => 'Sem comprovação suficiente',

                default => 'Não pesquisada',
            };

        return [
            'points' => $points,

            'not_researched' => $status === 'not_researched',

            'factor' => $this->factor(
                key: $dimension,
                label: $label,
                points: $points,
                maxPoints: $maxYes,
                detail: $detail,
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

    private function stringValue(
        mixed $value
    ): ?string {
        return is_string($value)
            && $value !== ''
                ? $value
                : null;
    }
}
