<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\HubSpotCompany;

final class HubSpotKnownCompanyLinkService
{
    /**
     * Quando o Prospector foi quem criou
     * a Company no HubSpot, já conhecemos
     * inequivocamente a Company fiscal de origem.
     *
     * Portanto esse vínculo é mais seguro do que
     * tentar redescobrir a identidade por nome,
     * domínio ou associação indireta.
     */
    public function link(
        HubSpotCompany $hubSpotCompany
    ): bool {
        $hubSpotId =
            trim(
                (string)
                    $hubSpotCompany
                        ->hubspot_id
            );

        if ($hubSpotId === '') {
            return false;
        }

        $lead =
            CompanyHubSpotLead::query()
                ->where(
                    'hubspot_company_id',
                    $hubSpotId
                )
                ->first();

        if ($lead === null) {
            return false;
        }

        $company =
            Company::query()
                ->find(
                    $lead->company_id
                );

        if ($company === null) {
            return false;
        }

        /*
         * Nunca sobrescrevemos silenciosamente
         * um vínculo fiscal confiável existente
         * apontando para outra Company.
         */
        if (
            $hubSpotCompany
                ->hasTrustedFiscalLink()
            && $hubSpotCompany
                ->company_id !== null
            && (int)
                $hubSpotCompany
                    ->company_id
                !== $company->id
        ) {
            return false;
        }

        $root =
            trim(
                (string)
                    $company
                        ->cnpj_root
            );

        if ($root === '') {
            return false;
        }

        $hubSpotCompany->forceFill([
            'company_id' => $company->id,

            'matched_cnpj_root' => $root,

            'matched_company_name' => $company
                ->corporate_name,

            /*
             * Vínculo criado pelo próprio
             * fluxo Prospector -> HubSpot.
             */
            'match_source' => 'prospector_created',
        ])->save();

        return true;
    }
}
