<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

final class CompanyPolicy
{
    /**
     * Gestores enxergam qualquer empresa.
     *
     * Vendedores também visualizam todas as empresas
     * e estabelecimentos da base compartilhada.
     */
    // EC_COMMERCIAL_WORKSPACE_V62_VIEW_ALL: leitura de todos os cadastros
    // da Receita e dos dossiês para usuários comerciais autenticados.
    public function view(User $user, Company $company): bool
    {
        return $user->isCommercialManager() || $user->isCommercialSeller();
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
     * Criação manual de oportunidade no HubSpot.
     *
     * Gestores podem trabalhar qualquer empresa.
     * Vendedores somente empresas da própria
     * carteira, seguindo a mesma regra de view.
     */
    public function createHubSpotOpportunity(
        User $user,
        Company $company,
    ): bool {
        return $this->canWorkOnAssignedCompany(
            $user,
            $company,
        );
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
        return $this->canWorkOnAssignedCompany(
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

    /**
     * A leitura é global; ações externas ainda exigem carteira ou gestor.
     */
    private function canWorkOnAssignedCompany(User $user, Company $company): bool
    {
        if ($user->isCommercialManager()) {
            return true;
        }

        return $user->isCommercialSeller()
            && $company->leadWorkState()
                ->where('assigned_user_id', $user->id)
                ->exists();
    }
}
