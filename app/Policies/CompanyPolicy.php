<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

final class CompanyPolicy
{
    /**
     * Gestores enxergam qualquer empresa.
     *
     * Vendedores enxergam somente empresas
     * da própria carteira.
     */
    public function view(
        User $user,
        Company $company,
    ): bool {
        if (
            $user
                ->isCommercialManager()
        ) {
            return true;
        }

        if (
            ! $user
                ->isCommercialSeller()
        ) {
            return false;
        }

        return $company
            ->leadWorkState()
            ->where(
                'assigned_user_id',
                $user->id
            )
            ->exists();
    }

    /**
     * Dados cadastrais do grupo empresarial
     * são administrados somente por gestores.
     */
    public function manageData(
        User $user,
        Company $company,
    ): bool {
        return $user
            ->isCommercialManager();
    }

    /**
     * Pesquisa normal respeita as regras
     * de ICP, CRM e cooldown.
     *
     * Portanto, vendedor responsável pela
     * empresa pode iniciar essa pesquisa.
     */
    public function researchExports(
        User $user,
        Company $company,
    ): bool {
        return $this->view(
            $user,
            $company,
        );
    }

    /**
     * Pesquisa forçada ignora os bloqueios
     * automáticos e pode gerar consumo externo.
     *
     * Fica restrita ao gestor.
     */
    public function forceResearchExports(
        User $user,
        Company $company,
    ): bool {
        return $user
            ->isCommercialManager();
    }
}
