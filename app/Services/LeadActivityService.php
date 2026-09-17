<?php

namespace App\Services;

use App\Models\CompanyLeadActivity;
use Carbon\CarbonInterface;

final class LeadActivityService
{
    public function statusChanged(
        int $companyId,
        ?string $fromStatus,
        string $toStatus,
    ): CompanyLeadActivity {
        return $this->record(
            companyId: $companyId,
            type: 'status_changed',
            title: 'Status comercial alterado',
            description: $this->statusLabel(
                $fromStatus
            )
                .' → '
                .$this->statusLabel(
                    $toStatus
                ),
            metadata: [
                'from_status' => $fromStatus,

                'to_status' => $toStatus,
            ],
        );
    }

    public function noteChanged(
        int $companyId,
        ?string $previousNote,
        ?string $newNote,
    ): CompanyLeadActivity {
        return $this->record(
            companyId: $companyId,
            type: 'note_updated',
            title: $newNote === null
                ? 'Observação removida'
                : 'Observação atualizada',
            description: $newNote,
            metadata: [
                'previous_note' => $previousNote,

                'new_note' => $newNote,
            ],
        );
    }

    public function followUpChanged(
        int $companyId,
        ?CarbonInterface $previousDate,
        ?CarbonInterface $newDate,
    ): CompanyLeadActivity {
        $description =
            $newDate === null
                ? 'Próxima ação removida'
                : 'Próxima ação definida para '
                    .$newDate->format(
                        'd/m/Y H:i'
                    );

        return $this->record(
            companyId: $companyId,
            type: 'follow_up_updated',
            title: 'Próxima ação atualizada',
            description: $description,
            metadata: [
                'previous_date' => $previousDate
                    ?->toIso8601String(),

                'new_date' => $newDate
                    ?->toIso8601String(),
            ],
        );
    }

    public function reprospectingStarted(
        int $companyId,
        string $fromStatus,
        ?string $fromStage,
        string $toStage,
    ): CompanyLeadActivity {
        return $this->record(
            companyId: $companyId,
            type: 'reprospecting_started',
            title: 'Lead retomado',
            description: $this->statusLabel(
                $fromStatus
            )
                .' → nova abordagem',
            metadata: [
                'from_status' => $fromStatus,
                'from_stage' => $fromStage,
                'to_stage' => $toStage,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        int $companyId,
        string $type,
        string $title,
        ?string $description,
        array $metadata,
    ): CompanyLeadActivity {
        $userId =
            auth()->id();

        if (
            $userId !== null
            && ! is_int($userId)
        ) {
            $userId =
                is_numeric($userId)
                    ? (int) $userId
                    : null;
        }

        return CompanyLeadActivity::query()
            ->create([
                'company_id' => $companyId,

                'user_id' => $userId,

                'type' => $type,

                'title' => $title,

                'description' => $description,

                'metadata' => $metadata,

                'occurred_at' => now(),
            ]);
    }

    private function statusLabel(
        ?string $status
    ): string {
        return match ($status) {
            'contacting' => 'Em contato',

            'waiting' => 'Aguardando retorno',

            'future' => 'Oportunidade futura',

            'refused' => 'Recusou',

            'discarded' => 'Descartado',

            'converted' => 'Convertido',

            default => 'Novo',
        };
    }
}
