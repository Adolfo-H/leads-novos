<?php

namespace App\Jobs;

use App\Contracts\CnpjDataProvider;
use App\Contracts\CnpjGroupDataProvider;
use App\Contracts\CrmCompanyProvider;
use App\Exceptions\CnpjNotFoundException;
use App\Exceptions\CnpjProviderTemporaryException;
use App\Models\Company;
use App\Models\ImportItem;
use App\Services\CnpjEnrichmentService;
use App\Services\CompanyGroupEnrichmentService;
use App\Services\CrmCheckService;
use App\Services\IcpScoringService;
use App\Services\ImportQueueService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class EnrichImportItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 300;

    public function __construct(
        public int $importItemId
    ) {}

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (
                new WithoutOverlapping(
                    'import-item-'
                    .$this->importItemId
                )
            )
                ->dontRelease()
                ->expireAfter(
                    $this->timeout
                    + 60
                ),
        ];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [
            60,
            300,
            900,
        ];
    }

    public function handle(
        CnpjGroupDataProvider $groupProvider,
        CompanyGroupEnrichmentService $groupEnrichment,
        CnpjDataProvider $pointProvider,
        CnpjEnrichmentService $pointEnrichment,
        CrmCompanyProvider $crmProvider,
        CrmCheckService $crmCheck,
        ImportQueueService $queue,
        IcpScoringService $icp,
    ): void {
        $item = ImportItem::query()
            ->findOrFail(
                $this->importItemId
            );

        if (
            in_array(
                $item->status,
                [
                    'completed',
                    'existing',
                    'duplicate',
                    'invalid',
                ],
                true
            )
        ) {
            return;
        }

        $item->update([
            'status' => 'processing',

            'error_message' => null,
        ]);

        try {
            try {
                /*
                 * Fonte principal:
                 * base local mensal da Receita.
                 *
                 * Enriquece a raiz inteira:
                 * matriz + filiais + CNAEs.
                 */
                $company =
                    $groupEnrichment
                        ->enrich(
                            $item
                                ->normalized_cnpj,
                            $groupProvider,
                        );

                $metadata = [
                    'provider' => $groupProvider
                        ->name(),

                    'group_enrichment' => true,

                    'establishments' => $company
                        ->establishments
                        ->count(),

                    'processed_at' => now()
                        ->toIso8601String(),
                ];

            } catch (
                CnpjNotFoundException
            ) {
                /*
                 * Empresa muito nova pode ainda
                 * não existir na fotografia mensal.
                 *
                 * BrasilAPI fica como fallback
                 * para consulta pontual.
                 */
                $data =
                    $pointProvider
                        ->lookup(
                            $item
                                ->normalized_cnpj
                        );

                $company =
                    $pointEnrichment
                        ->enrich(
                            $item
                                ->normalized_cnpj,
                            $data,
                            $pointProvider
                                ->name(),
                        );

                $icp->calculate(
                    $company
                );

                $metadata = [
                    'provider' => $pointProvider
                        ->name(),

                    'group_enrichment' => false,

                    'fallback_reason' => 'not_found_in_local_receita',

                    'response' => $data,

                    'processed_at' => now()
                        ->toIso8601String(),
                ];
            }

            /*
             * A CompanyGroupEnrichmentService
             * já recalcula o ICP.
             *
             * No fallback pontual ele foi
             * calculado explicitamente acima.
             */

            $metadata['crm'] =
                $this->checkCrm(
                    $company,
                    $crmProvider,
                    $crmCheck,
                );

            $item->update([
                'status' => 'completed',

                'company_id' => $company->id,

                'error_message' => null,

                'metadata' => $metadata,
            ]);

        } catch (
            CnpjNotFoundException $exception
        ) {
            $item->update([
                'status' => 'failed',

                'error_message' => mb_substr(
                    $exception
                        ->getMessage(),
                    0,
                    2000
                ),
            ]);

        } catch (
            CnpjProviderTemporaryException $exception
        ) {
            $item->update([
                'status' => 'queued',

                'error_message' => mb_substr(
                    $exception
                        ->getMessage(),
                    0,
                    2000
                ),
            ]);

            throw $exception;
        } catch (
            Throwable $exception
        ) {
            $item->update([
                'status' => 'failed',

                'error_message' => mb_substr(
                    $exception
                        ->getMessage(),
                    0,
                    2000
                ),
            ]);

        } finally {
            $queue->refreshBatch(
                $item->import_batch_id
            );
        }
    }

    /**
     * O CRM é enriquecimento comercial.
     *
     * Uma indisponibilidade do HubSpot
     * não deve invalidar os dados já
     * obtidos da Receita.
     *
     * @return array<string, mixed>
     */
    private function checkCrm(
        Company $company,
        CrmCompanyProvider $provider,
        CrmCheckService $service,
    ): array {
        try {
            $check =
                $service->check(
                    $company,
                    $provider,
                );

            return [
                'checked' => true,

                'provider' => $check->provider,

                'status' => $check->status,

                'external_id' => $check->external_id,

                'matched_by' => $check->matched_by,

                'matched_value' => $check->matched_value,

                'checked_at' => (string) $check->checked_at,
            ];

        } catch (
            Throwable $exception
        ) {
            return [
                'checked' => false,

                'provider' => $provider->name(),

                'status' => 'error',

                'error' => mb_substr(
                    $exception
                        ->getMessage(),
                    0,
                    1000
                ),
            ];
        }
    }

    public function failed(
        Throwable $exception
    ): void {
        $item = ImportItem::query()
            ->find(
                $this->importItemId
            );

        if (! $item) {
            return;
        }

        if (
            $item->status
            === 'completed'
        ) {
            return;
        }

        $item->update([
            'status' => 'failed',

            'error_message' => mb_substr(
                $exception
                    ->getMessage(),
                0,
                2000
            ),
        ]);

        app(
            ImportQueueService::class
        )->refreshBatch(
            $item->import_batch_id
        );
    }
}
