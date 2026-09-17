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

        /*
         * Tarefa aberta possui prioridade
         * sobre atividade já realizada.
         */
        if ($openTasks > 0) {
            return 'waiting';
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
                $stages[] = $stage;
            }
        }

        return $stages;
    }
}
