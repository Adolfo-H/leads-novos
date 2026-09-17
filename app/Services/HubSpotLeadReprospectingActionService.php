<?php

namespace App\Services;

use App\Models\CompanyHubSpotLead;
use DomainException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class HubSpotLeadReprospectingActionService
{
    public function __construct(
        private readonly HubSpotLeadReprospectingService $policy,
        private readonly HubSpotLeadStatusSyncService $statusSync,
        private readonly LeadActivityService $activities,
    ) {}

    public function resume(
        CompanyHubSpotLead $lead
    ): CompanyHubSpotLead {
        $freshLead =
            $lead->fresh();

        if ($freshLead !== null) {
            $lead =
                $freshLead;
        }

        $decision =
            $this->policy->evaluate(
                $lead
            );

        if (
            ! $decision['applicable']
            || ! $decision['eligible']
        ) {
            throw new DomainException(
                $decision['message']
            );
        }

        $dealId =
            trim(
                (string) $lead
                    ->hubspot_deal_id
            );

        if ($dealId === '') {
            throw new RuntimeException(
                'Lead não possui negócio associado no HubSpot.'
            );
        }

        $targetStage =
            trim(
                (string) config(
                    'services.hubspot.lead_initial_stage',
                    'appointmentscheduled'
                )
            );

        if ($targetStage === '') {
            throw new RuntimeException(
                'Etapa inicial do HubSpot não está configurada.'
            );
        }

        $previousStatus =
            trim(
                (string) $lead
                    ->work_status
            );

        $previousStage =
            $this->stringValue(
                $lead->deal_stage_id
            );

        /*
         * A retomada reutiliza o mesmo negócio.
         *
         * Evitamos criar outro Deal para não
         * fragmentar o histórico comercial.
         */
        $this->moveDeal(
            dealId: $dealId,
            targetStage: $targetStage,
        );

        /*
         * O evento manual é gravado logo após
         * o HubSpot aceitar a movimentação.
         */
        $this->activities
            ->reprospectingStarted(
                companyId: $lead->company_id,
                fromStatus: $previousStatus,
                fromStage: $previousStage,
                toStage: $targetStage,
            );

        try {
            /*
             * Reconsulta imediatamente o HubSpot.
             *
             * Se ainda existir tarefa aberta,
             * o lead volta como waiting.
             *
             * Caso contrário, volta como
             * contacting.
             */
            return $this
                ->statusSync
                ->sync(
                    $lead
                );
        } catch (Throwable $exception) {
            /*
             * A mudança remota já aconteceu.
             *
             * Não devemos informar ao usuário
             * que a retomada falhou quando apenas
             * a reconciliação posterior falhou.
             */
            report(
                $exception
            );

            $fallbackStatus =
                (int) $lead->open_task_count > 0
                    ? 'waiting'
                    : 'contacting';

            $lead->forceFill([
                'deal_stage_id' => $targetStage,

                'work_status' => $fallbackStatus,

                'work_status_changed_at' => now(),

                /*
                 * NULL faz o scheduler priorizar
                 * este lead na próxima rodada.
                 */
                'status_synced_at' => null,

                'sync_error' => 'Retomada aplicada no HubSpot; aguardando reconciliação automática.',
            ])->save();

            return $lead->refresh();
        }
    }

    private function moveDeal(
        string $dealId,
        string $targetStage,
    ): void {
        $response =
            $this->client()
                ->patch(
                    $this->baseUrl()
                    .'/crm/v3/objects/deals/'
                    .rawurlencode(
                        $dealId
                    ),
                    [
                        'properties' => [
                            'dealstage' => $targetStage,
                        ],
                    ]
                );

        if (! $response->successful()) {
            throw new RuntimeException(
                'HubSpot não aceitou a retomada do negócio (HTTP '
                .$response->status()
                .').'
            );
        }
    }

    private function client(): PendingRequest
    {
        $token =
            trim(
                (string) config(
                    'services.hubspot.access_token'
                )
            );

        if ($token === '') {
            throw new RuntimeException(
                'Token do HubSpot não configurado.'
            );
        }

        return Http::withToken(
            $token
        )
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }

    private function baseUrl(): string
    {
        $baseUrl =
            trim(
                (string) config(
                    'services.hubspot.base_url',
                    'https://api.hubapi.com'
                )
            );

        return rtrim(
            $baseUrl !== ''
                ? $baseUrl
                : 'https://api.hubapi.com',
            '/'
        );
    }

    private function stringValue(
        mixed $value
    ): ?string {
        if (! is_scalar($value)) {
            return null;
        }

        $value =
            trim(
                (string) $value
            );

        return $value !== ''
            ? $value
            : null;
    }
}
