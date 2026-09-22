<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanySdrScore;

final class SdrScoringService
{
    private const VERSION = 'v3';

    /**
     * Cliente não deve voltar para a fila
     * operacional SDR.
     *
     * Oportunidade aberta NÃO é mais bloqueada:
     * ela continua recebendo score e prioridade.
     *
     * @var list<string>
     */
    private const BLOCKED_CRM_STATUSES = [
        'client',
    ];

    public function __construct(
        private readonly LeadQualificationScoreService $qualification,
    ) {}

    public function recalculate(
        Company $company
    ): CompanySdrScore {
        $company->loadMissing([
            'crmCheck',
        ]);

        $crm =
            $company->crmCheck;

        $crmStatus =
            $this->stringValue(
                $crm?->status
            );

        /*
         * Cliente continua bloqueado para SDR.
         */
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

        $result =
            $this->qualification
                ->calculate(
                    $company
                );

        return CompanySdrScore::query()
            ->updateOrCreate(
                [
                    'company_id' => $company->id,
                ],
                [
                    'score' => $result[
                            'score'
                        ],

                    'priority' => $result[
                            'priority'
                        ],

                    'label' => $result[
                            'label'
                        ],

                    'is_eligible' => true,

                    'is_provisional' => ! $result[
                            'research_complete'
                        ],

                    'blocked_reason' => null,

                    'factors' => $result[
                            'factors'
                        ],

                    'version' => self::VERSION,

                    'metadata' => [
                        'research_complete' => $result[
                                'research_complete'
                            ],

                        'crm_status' => $crmStatus,

                        'commercial_status' => $result[
                                'commercial_status'
                            ],

                        'work_status' => $result[
                                'work_status'
                            ],

                        'icp_raw_score' => $result[
                                'icp_raw_score'
                            ],

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

                'prospected' => 'Empresa prospectada recentemente',

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

                    'factors' => [],

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

    private function stringValue(
        mixed $value
    ): ?string {
        return is_string(
            $value
        )
            && trim(
                $value
            ) !== ''
                ? trim(
                    $value
                )
                : null;
    }
}
