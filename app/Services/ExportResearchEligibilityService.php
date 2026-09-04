<?php

namespace App\Services;

use App\Models\Company;
use Carbon\CarbonImmutable;

final class ExportResearchEligibilityService
{
    public function __construct(
        private readonly CrmReprospectingPolicyService $reprospecting,
    ) {}

    /**
     * @return array{
     *     eligible: bool,
     *     reason: string,
     *     message: string
     * }
     */
    public function evaluate(
        Company $company
    ): array {
        $company->loadMissing([
            'icpScore',
            'crmCheck',
            'exportIntelligence',
        ]);

        $crm =
            $company->crmCheck;

        /*
         * Em fluxo automático exigimos que o
         * CRM tenha sido verificado antes.
         */
        if (! $crm) {
            return $this->blocked(
                reason: 'crm_not_checked',
                message: 'CRM ainda não foi verificado.'
            );
        }

        $blockedStatuses =
            config(
                'prospector.export_research.blocked_crm_statuses',
                []
            );

        if (
            is_array($blockedStatuses)
            && in_array(
                $crm->status,
                $blockedStatuses,
                true
            )
        ) {
            return $this->blocked(
                reason: 'crm_'.$crm->status,

                message: match ($crm->status) {
                    'client' => 'Empresa já é cliente.',

                    'opportunity' => 'Empresa já possui oportunidade.',

                    'prospected' => 'Empresa já foi prospectada.',

                    default => 'Empresa já está sendo trabalhada.',
                }
            );
        }

        /*
         * Prospectado pode voltar para pesquisa
         * quando terminar o período de carência.
         */
        if (
            $crm->status
            === 'prospected'
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
                return $this->blocked(
                    reason: 'crm_prospected_'
                        .$reprospecting[
                            'reason'
                        ],

                    message: $reprospecting[
                            'message'
                        ]
                );
            }
        }

        $icp =
            $company->icpScore;

        if (! $icp) {
            return $this->blocked(
                reason: 'icp_not_calculated',
                message: 'ICP ainda não foi calculado.'
            );
        }

        $eligibleGrades =
            config(
                'prospector.export_research.eligible_icp_grades',
                [
                    'A',
                    'B',
                ]
            );

        if (
            ! is_array($eligibleGrades)
            || ! in_array(
                $icp->grade,
                $eligibleGrades,
                true
            )
        ) {
            return $this->blocked(
                reason: 'low_icp',
                message: 'Empresa não possui ICP '
                    .'suficiente para pesquisa automática.'
            );
        }

        $intelligence =
            $company->exportIntelligence;

        if (
            $intelligence
            && $intelligence->researched_at
        ) {
            $cooldownDays =
                max(
                    1,
                    (int) config(
                        'prospector.export_research.cooldown_days',
                        30
                    )
                );

            $researchedAt =
                CarbonImmutable::parse(
                    (string) $intelligence
                        ->researched_at
                );

            $nextAllowedAt =
                $researchedAt
                    ->addDays(
                        $cooldownDays
                    );

            if (
                now()->lt(
                    $nextAllowedAt
                )
            ) {
                return $this->blocked(
                    reason: 'recently_researched',

                    message: 'Empresa já foi pesquisada '
                        .'recentemente.'
                );
            }
        }

        return [
            'eligible' => true,

            'reason' => 'eligible',

            'message' => 'Empresa elegível para '
                .'pesquisa automática.',
        ];
    }

    /**
     * @return array{
     *     eligible: false,
     *     reason: string,
     *     message: string
     * }
     */
    private function blocked(
        string $reason,
        string $message,
    ): array {
        return [
            'eligible' => false,

            'reason' => $reason,

            'message' => $message,
        ];
    }
}
