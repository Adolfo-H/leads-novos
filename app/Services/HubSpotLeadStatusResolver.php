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
        $discardedStages =
            config(
                'services.hubspot.lead_discarded_stages',
                []
            );

        if (! is_array($discardedStages)) {
            $discardedStages = [];
        }

        if (
            $dealStage !== null
            && in_array(
                $dealStage,
                $discardedStages,
                true
            )
        ) {
            return 'discarded';
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
}
