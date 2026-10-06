<?php

namespace App\Services;

use Carbon\CarbonInterface;

final class HubSpotLeadStatusResolver
{
    public function resolve(
        ?string $dealStage,
        int $openTasks,
        int $contactedCount,
        ?CarbonInterface $lastActivityAt,
    ): string {
        /*
         * Estados realmente finais continuam
         * prevalecendo sobre qualquer follow-up.
         *
         * Um negócio ganho ou descartado não
         * deve reaparecer na fila operacional
         * apenas porque sobrou uma tarefa antiga.
         */
        if (
            $dealStage !== null
            && in_array(
                $dealStage,
                $this->stages(
                    'services.hubspot.lead_converted_stages'
                ),
                true
            )
        ) {
            return 'converted';
        }

        if (
            $dealStage !== null
            && in_array(
                $dealStage,
                $this->stages(
                    'services.hubspot.lead_discarded_stages'
                ),
                true
            )
        ) {
            return 'discarded';
        }

        /*
         * Uma tarefa aberta significa que existe
         * uma ação comercial concreta pendente.
         *
         * Portanto:
         *
         * Recusou + tarefa aberta
         *      => Aguardando retorno
         *
         * Oportunidade futura + tarefa aberta
         *      => Aguardando retorno
         *
         * A etapa do negócio continua aparecendo
         * separadamente no CRM / HubSpot.
         */
        if ($openTasks > 0) {
            return 'waiting';
        }

        /*
         * Sem tarefa pendente, respeitamos as
         * etapas especiais do negócio.
         */
        if (
            $dealStage !== null
            && in_array(
                $dealStage,
                $this->stages(
                    'services.hubspot.lead_refused_stages'
                ),
                true
            )
        ) {
            return 'refused';
        }

        if (
            $dealStage !== null
            && in_array(
                $dealStage,
                $this->stages(
                    'services.hubspot.lead_future_stages'
                ),
                true
            )
        ) {
            return 'future';
        }

        if (
            $contactedCount > 0
            || $lastActivityAt !== null
        ) {
            return 'contacting';
        }

        return 'new';
    }

    /**
     * @return list<string>
     */
    private function stages(
        string $configKey
    ): array {
        $raw =
            config(
                $configKey,
                []
            );

        if (! is_array($raw)) {
            return [];
        }

        $stages = [];

        foreach ($raw as $stage) {
            if (! is_scalar($stage)) {
                continue;
            }

            $stage =
                trim(
                    (string) $stage
                );

            if ($stage !== '') {
                $stages[] =
                    $stage;
            }
        }

        return array_values(
            array_unique(
                $stages
            )
        );
    }
}
