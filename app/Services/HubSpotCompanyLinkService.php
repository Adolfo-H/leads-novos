<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyHubSpotLead;
use App\Models\CompanyLeadWorkState;
use App\Models\HubSpotCompany;
use App\Models\User;
use App\Services\Providers\ReceitaLocalCnpjGroupProvider;
use App\Support\Cnpj;
use App\Support\TextNormalizer;
use DomainException;
use Illuminate\Support\Facades\DB;

final class HubSpotCompanyLinkService
{
    public function __construct(
        private readonly HubSpotMirrorCrmSyncService $crm,
        private readonly HubSpotMirrorLeadProjectionService $projections,
        private readonly CompanyGroupEnrichmentService $groups,
        private readonly ReceitaLocalCnpjGroupProvider $receita,
        private readonly HubSpotActivitySyncService $activities,
    ) {}

    public function importAndLink(
        HubSpotCompany $hubSpotCompany,
        string $cnpj,
    ): CompanyHubSpotLead {
        $cnpj =
            Cnpj::normalize(
                $cnpj
            );

        Cnpj::assertValid(
            $cnpj
        );

        $root =
            Cnpj::root(
                $cnpj
            );

        /*
         * Proteção contra duplicidade:
         *
         * se outra tela/processo já cadastrou
         * esta raiz, reutilizamos a Company.
         */
        $company =
            Company::query()
                ->where(
                    'cnpj_root',
                    $root
                )
                ->first();

        if ($company === null) {
            $company =
                $this
                    ->groups
                    ->enrich(
                        $cnpj,
                        $this->receita,
                    );
        }

        return $this->link(
            hubSpotCompany: $hubSpotCompany,

            company: $company,
        );
    }

    public function link(
        HubSpotCompany $hubSpotCompany,
        Company $company,
    ): CompanyHubSpotLead {
        return DB::transaction(
            function () use (
                $hubSpotCompany,
                $company,
            ): CompanyHubSpotLead {
                $hubSpotCompany =
                    HubSpotCompany::query()
                        ->whereKey(
                            $hubSpotCompany->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                $company =
                    Company::query()
                        ->whereKey(
                            $company->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                $currentCompanyId =
                    is_numeric(
                        $hubSpotCompany->company_id
                    )
                        ? (int) $hubSpotCompany
                            ->company_id
                        : null;

                if (
                    $hubSpotCompany
                        ->hasTrustedFiscalLink()
                    && $currentCompanyId !== null
                    && $currentCompanyId
                        !== $company->id
                ) {
                    throw new DomainException(
                        'Este registro do HubSpot já está vinculado a outra empresa.'
                    );
                }

                $cnpjRoot =
                    trim(
                        (string)
                        $company->cnpj_root
                    );

                if ($cnpjRoot === '') {
                    throw new DomainException(
                        'A empresa selecionada não possui raiz de CNPJ.'
                    );
                }

                $existingRoot =
                    trim(
                        (string)
                        $hubSpotCompany
                            ->matched_cnpj_root
                    );

                if (
                    $hubSpotCompany
                        ->hasTrustedFiscalLink()
                    && $existingRoot !== ''
                    && $existingRoot !== $cnpjRoot
                ) {
                    throw new DomainException(
                        'O registro HubSpot possui um CNPJ identificado diferente da empresa selecionada.'
                    );
                }

                $hubSpotCompany->forceFill([
                    'company_id' => $company->id,

                    'matched_cnpj_root' => $cnpjRoot,

                    'matched_company_name' => $company->corporate_name,

                    'match_source' => 'manual_manager',
                ])->save();

                /*
                 * Atividades recebidas antes da
                 * identificação fiscal passam
                 * para o histórico da Company.
                 */
                $this
                    ->activities
                    ->promoteForCompany(
                        hubSpotCompany: $hubSpotCompany,

                        company: $company,
                    );

                /*
                 * Recria CRM consolidado e score.
                 */
                $this->crm->sync(
                    $company
                );

                /*
                 * Recria projeção comercial.
                 */
                $lead =
                    $this
                        ->projections
                        ->sync(
                            $company
                        );

                $this->syncWorkState(
                    $lead
                );

                return $lead->refresh();
            }
        );
    }

    private function syncWorkState(
        CompanyHubSpotLead $lead
    ): void {
        $ownerId =
            $this->ownerId(
                data_get(
                    $lead->metadata ?? [],
                    'owner_names',
                    []
                )
            );

        $state =
            CompanyLeadWorkState::query()
                ->where(
                    'company_id',
                    $lead->company_id
                )
                ->lockForUpdate()
                ->first();

        if ($state === null) {
            CompanyLeadWorkState::query()
                ->create([
                    'company_id' => $lead->company_id,

                    'assigned_user_id' => $ownerId,

                    'status' => $lead->work_status,

                    'last_action_at' => $lead->last_activity_at,

                    'next_action_at' => $lead->last_task_due_at,
                ]);

            return;
        }

        $payload = [
            'status' => $lead->work_status,

            'last_action_at' => $lead->last_activity_at,

            'next_action_at' => $lead->last_task_due_at,
        ];

        /*
         * Nunca substituímos carteira manual.
         */
        if (
            $state->assigned_user_id === null
            && $ownerId !== null
        ) {
            $payload[
                'assigned_user_id'
            ] =
                $ownerId;
        }

        $state->update(
            $payload
        );
    }

    private function ownerId(
        mixed $ownerNames
    ): ?int {
        if (! is_array($ownerNames)) {
            return null;
        }

        $usersByName = [];

        $users =
            User::query()
                ->whereNotNull(
                    'email_verified_at'
                )
                ->get([
                    'id',
                    'name',
                ]);

        foreach ($users as $user) {
            $normalized =
                TextNormalizer::companyName(
                    $user->name
                );

            if ($normalized === null) {
                continue;
            }

            $usersByName[
                $normalized
            ] ??= [];

            $usersByName[
                $normalized
            ][] =
                $user;
        }

        foreach ($ownerNames as $ownerName) {
            if (! is_string($ownerName)) {
                continue;
            }

            $normalized =
                TextNormalizer::companyName(
                    $ownerName
                );

            if ($normalized === null) {
                continue;
            }

            $matches =
                $usersByName[
                    $normalized
                ]
                ?? [];

            if (count($matches) === 1) {
                return (int)
                    $matches[0]->id;
            }
        }

        return null;
    }
}
