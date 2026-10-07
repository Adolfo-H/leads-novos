<?php

namespace App\Services;

use App\Models\Company;

final class HubSpotCompanyContactSyncService
{
    public function __construct(
        private readonly HubSpotContactCandidateService $candidateService,
        private readonly HubSpotContactResolverService $contactResolver,
        private readonly HubSpotAssociationService $associationService,
    ) {}

    /**
     * Sincroniza TODOS os canais cadastrais.
     *
     * Estratégia:
     *
     * - 1 Contact por e-mail único;
     * - phone + mobilephone quando disponíveis;
     * - contatos somente com telefone quando
     *   não existe e-mail correspondente;
     * - nenhum e-mail/telefone único é perdido.
     *
     * @param  callable(int, int): void|null  $onProgress
     * @return list<array{
     *     id: string,
     *     email: string|null,
     *     phone: string|null,
     *     mobilephone: string|null
     * }>
     */
    public function sync(
        Company $company,
        string $hubSpotCompanyId,
        ?string $hubSpotDealId = null,
        ?string $hubSpotTaskId = null,
        ?string $ownerId = null,
        ?callable $onProgress = null,
    ): array {
        $company->loadMissing(
            'establishments'
        );

        $candidates =
            $this->candidates(
                $company
            );

        $total =
            count(
                $candidates
            );

        $contacts = [];

        foreach (
            $candidates as $index => $candidate
        ) {
            $contactId =
                $this->contactResolver->resolve(
                    company: $company,

                    email: $candidate['email'],

                    phone: $candidate['phone'],

                    mobilePhone: $candidate['mobilephone'],

                    ownerId: $ownerId,
                );

            $this->associationService->associate(
                fromType: 'companies',

                fromId: $hubSpotCompanyId,

                toType: 'contacts',

                toId: $contactId,
            );

            if (
                $hubSpotDealId !== null
                && trim(
                    $hubSpotDealId
                ) !== ''
            ) {
                $this->associationService->associate(
                    fromType: 'contacts',

                    fromId: $contactId,

                    toType: 'deals',

                    toId: $hubSpotDealId,
                );
            }

            if (
                $hubSpotTaskId !== null
                && trim(
                    $hubSpotTaskId
                ) !== ''
            ) {
                $this->associationService->associate(
                    fromType: 'tasks',

                    fromId: $hubSpotTaskId,

                    toType: 'contacts',

                    toId: $contactId,
                );
            }

            $contacts[] = [
                'id' => $contactId,

                'email' => $candidate[
                        'email'
                    ],

                'phone' => $candidate[
                        'phone'
                    ],

                'mobilephone' => $candidate[
                        'mobilephone'
                    ],
            ];

            if ($onProgress !== null) {
                $onProgress(
                    $index + 1,
                    $total,
                );
            }
        }

        return $contacts;
    }

    /**
     * @return list<array{
     *     email: string|null,
     *     phone: string|null,
     *     mobilephone: string|null
     * }>
     */
    /**
     * Mantido público por compatibilidade com
     * diagnósticos e testes já existentes.
     *
     * @return list<array{
     *     email: string|null,
     *     phone: string|null,
     *     mobilephone: string|null
     * }>
     */
    public function candidates(
        Company $company
    ): array {
        return $this
            ->candidateService
            ->collect(
                $company
            );
    }
}
