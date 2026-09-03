<?php

namespace App\Jobs;

use App\Contracts\CnpjDataProvider;
use App\Exceptions\CnpjNotFoundException;
use App\Exceptions\CnpjProviderTemporaryException;
use App\Models\ImportItem;
use App\Services\CnpjEnrichmentService;
use App\Services\IcpScoringService;
use App\Services\ImportQueueService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EnrichImportItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    public function __construct(
        public int $importItemId
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [
            60,
            300,
            900,
        ];
    }

    public function handle(
        CnpjDataProvider $provider,
        CnpjEnrichmentService $enrichment,
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
            $data = $provider
                ->lookup(
                    $item->normalized_cnpj
                );

            $company = $enrichment
                ->enrich(
                    $item->normalized_cnpj,
                    $data,
                    $provider->name(),
                );

            $icp->calculate(
                $company
            );

            $item->update([
                'status' => 'completed',

                'company_id' => $company->id,

                'error_message' => null,

                'metadata' => [
                    'provider' => $provider->name(),

                    'response' => $data,

                    'processed_at' => now()
                        ->toIso8601String(),
                ],
            ]);
        } catch (
            CnpjNotFoundException $exception
        ) {
            /*
             * Não adianta tentar novamente.
             */
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
            /*
             * Mantemos como queued.
             *
             * Como a exceção é relançada,
             * Laravel respeitará o backoff
             * e tentará novamente.
             */
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
        } catch (Throwable $exception) {
            /*
             * Erro permanente de conteúdo,
             * normalização ou configuração.
             */
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
                $exception->getMessage(),
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
