<?php

namespace App\Services;

use App\Models\Company;

final class HubSpotLeadEligibilityService
{
    /**
     * @return array{
     *     eligible: bool,
     *     reason: string
     * }
     */
    public function evaluate(
        Company $company
    ): array {
        $company->loadMissing([
            'sdrScore',
            'crmCheck',
            'exportIntelligence',
            'hubSpotLead',
        ]);

        if (
            $company->hubSpotLead
            !== null
        ) {
            return [
                'eligible' => false,

                'reason' => 'Empresa já sincronizada com o HubSpot.',
            ];
        }

        $score =
            $company->sdrScore;

        if ($score === null) {
            return [
                'eligible' => false,

                'reason' => 'Score SDR ainda não calculado.',
            ];
        }

        if (
            ! $score->is_eligible
        ) {
            return [
                'eligible' => false,

                'reason' => 'Empresa bloqueada pelo SDR.',
            ];
        }

        if (
            $score->is_provisional
        ) {
            return [
                'eligible' => false,

                'reason' => 'Score ainda é provisório.',
            ];
        }

        $minimumScore =
            (int) config(
                'services.hubspot.lead_min_score',
                70
            );

        if (
            (int) $score->score
            < $minimumScore
        ) {
            return [
                'eligible' => false,

                'reason' => 'Score abaixo do mínimo de '
                    .$minimumScore
                    .'.',
            ];
        }

        $crm =
            $company->crmCheck;

        if ($crm === null) {
            return [
                'eligible' => false,

                'reason' => 'CRM ainda não verificado.',
            ];
        }

        /*
         * Primeira versão:
         *
         * só criamos automaticamente quando
         * a empresa realmente NÃO existe
         * no HubSpot.
         *
         * Isso evita duplicidade.
         */
        if (
            $crm->status
            !== 'not_found'
        ) {
            return [
                'eligible' => false,

                'reason' => 'Empresa já possui histórico no HubSpot.',
            ];
        }

        return [
            'eligible' => true,

            'reason' => 'Lead qualificado para envio ao HubSpot.',
        ];
    }
}
