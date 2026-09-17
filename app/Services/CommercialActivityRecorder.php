<?php

namespace App\Services;

use App\Models\CompanyHubSpotLead;
use App\Models\CompanyLeadActivity;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

final class CommercialActivityRecorder
{
    public function recordLeadSynced(
        CompanyHubSpotLead $lead
    ): void {
        $exists =
            CompanyLeadActivity::query()
                ->where(
                    'company_id',
                    $lead->company_id
                )
                ->where(
                    'type',
                    'hubspot_synced'
                )
                ->exists();

        if ($exists) {
            return;
        }

        $this->record(
            companyId: $lead->company_id,
            type: 'hubspot_synced',
            title: 'Lead enviado ao HubSpot',
            description: 'Empresa e negócio sincronizados com o HubSpot.',
            metadata: [
                'hubspot_company_id' => $lead->hubspot_company_id,

                'hubspot_contact_id' => $lead->hubspot_contact_id,

                'hubspot_deal_id' => $lead->hubspot_deal_id,

                'pipeline_id' => $lead->pipeline_id,

                'deal_stage_id' => $lead->deal_stage_id,
            ],
            occurredAt: $this->dateOrNow(
                $lead->synced_at
            ),
        );
    }

    public function recordCurrentSnapshot(
        CompanyHubSpotLead $lead
    ): void {
        $exists =
            CompanyLeadActivity::query()
                ->where(
                    'company_id',
                    $lead->company_id
                )
                ->where(
                    'type',
                    'hubspot_snapshot'
                )
                ->exists();

        if ($exists) {
            return;
        }

        $lastTaskDueAt =
            $this->dateValue(
                $lead->last_task_due_at
            );

        $lastActivityAt =
            $this->dateValue(
                $lead->last_activity_at
            );

        $description =
            'Status: '
            .$this->statusLabel(
                $lead->work_status
            )
            .' · Etapa: '
            .$this->stageLabel(
                $lead->deal_stage_id
            )
            .' · Tarefas abertas: '
            .(int) $lead->open_task_count;

        $this->record(
            companyId: $lead->company_id,

            type: 'hubspot_snapshot',

            title: 'Estado comercial importado',

            description: $description,

            metadata: [
                'work_status' => $lead->work_status,

                'deal_stage_id' => $lead->deal_stage_id,

                'open_task_count' => (int) $lead->open_task_count,

                'last_task_due_at' => $lastTaskDueAt
                    ?->toIso8601String(),

                'last_activity_at' => $lastActivityAt
                    ?->toIso8601String(),
            ],

            occurredAt: $this->dateOrNow(
                $lead->status_synced_at
                ?? null
            ),
        );
    }

    public function recordHubSpotChanges(
        CompanyHubSpotLead $lead,
        ?string $previousStatus,
        ?string $previousStage,
        int $previousOpenTasks,
        ?string $previousDueAt,
    ): void {
        $currentStatus =
            $lead->work_status;

        $currentStage =
            $lead->deal_stage_id;

        $currentOpenTasks =
            (int) $lead->open_task_count;

        $currentTaskDueAt =
            $this->dateValue(
                $lead->last_task_due_at
            );

        $currentDueAt =
            $currentTaskDueAt
                ?->toIso8601String();

        if (
            $previousStatus
            !== $currentStatus
        ) {
            $this->record(
                companyId: $lead->company_id,

                type: 'hubspot_status_changed',

                title: 'Status comercial alterado',

                description: $this->statusLabel(
                    $previousStatus
                )
                    .' → '
                    .$this->statusLabel(
                        $currentStatus
                    ),

                metadata: [
                    'before' => $previousStatus,

                    'after' => $currentStatus,
                ],

                occurredAt: CarbonImmutable::now(),
            );
        }

        if (
            $previousStage
            !== $currentStage
        ) {
            $this->record(
                companyId: $lead->company_id,

                type: 'hubspot_stage_changed',

                title: 'Etapa do negócio alterada',

                description: $this->stageLabel(
                    $previousStage
                )
                    .' → '
                    .$this->stageLabel(
                        $currentStage
                    ),

                metadata: [
                    'before' => $previousStage,

                    'after' => $currentStage,
                ],

                occurredAt: CarbonImmutable::now(),
            );
        }

        if (
            $previousOpenTasks
                !== $currentOpenTasks
            || $previousDueAt
                !== $currentDueAt
        ) {
            $description =
                $currentOpenTasks > 0
                    ? $currentOpenTasks
                        .' tarefa(s) aberta(s)'
                    : 'Nenhuma tarefa aberta';

            if (
                $currentTaskDueAt
                !== null
            ) {
                $description .=
                    ' · Próximo prazo: '
                    .$currentTaskDueAt
                        ->format(
                            'd/m/Y H:i'
                        );
            }

            $this->record(
                companyId: $lead->company_id,

                type: 'hubspot_task_changed',

                title: $currentOpenTasks > 0
                        ? 'Follow-up atualizado'
                        : 'Follow-up concluído',

                description: $description,

                metadata: [
                    'previous_open_tasks' => $previousOpenTasks,

                    'open_tasks' => $currentOpenTasks,

                    'previous_due_at' => $previousDueAt,

                    'due_at' => $currentDueAt,
                ],

                occurredAt: CarbonImmutable::now(),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        int $companyId,
        string $type,
        string $title,
        string $description,
        array $metadata,
        CarbonImmutable $occurredAt,
    ): void {
        $duplicate =
            CompanyLeadActivity::query()
                ->where(
                    'company_id',
                    $companyId
                )
                ->where(
                    'type',
                    $type
                )
                ->where(
                    'title',
                    $title
                )
                ->where(
                    'description',
                    $description
                )
                ->where(
                    'occurred_at',
                    '>=',
                    now()->subMinute()
                )
                ->exists();

        if ($duplicate) {
            return;
        }

        CompanyLeadActivity::query()
            ->create([
                'company_id' => $companyId,

                'type' => $type,

                'title' => $title,

                'description' => $description,

                'metadata' => $metadata,

                'occurred_at' => $occurredAt,
            ]);
    }

    private function statusLabel(
        ?string $status
    ): string {
        return match ($status) {
            'new' => 'Novo',

            'contacting' => 'Em contato',

            'waiting' => 'Aguardando retorno',

            'future' => 'Oportunidade futura',

            'refused' => 'Recusou',

            'converted' => 'Convertido',

            'discarded' => 'Descartado',

            null, '' => 'Sem status',

            default => $status,
        };
    }

    private function stageLabel(
        ?string $stage
    ): string {
        return match ($stage) {
            'appointmentscheduled' => 'Prospects',

            'qualifiedtobuy' => 'Leds frio',

            'presentationscheduled' => 'Leds qualificado',

            '122191633' => 'Reunião Agendada',

            '122191634' => 'Reunião Cancelada',

            '122191635' => 'Reunião Realizada',

            'decisionmakerboughtin' => 'Proposta apresentada',

            'contractsent' => 'Aceite da proposta',

            '14249606' => 'Contrato Assinado',

            '14249607' => 'Início do teste',

            'closedwon' => 'Negócio fechado',

            'closedlost' => 'Recusou',

            '13185626' => 'Oportunidade Futura',

            '13185627' => 'Descartes',

            '13185628' => 'Leads fora foco',

            null, '' => 'Sem etapa',

            default => $stage,
        };
    }

    private function dateValue(
        mixed $value
    ): ?CarbonImmutable {
        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        try {
            if (
                $value
                instanceof DateTimeInterface
            ) {
                return CarbonImmutable::instance(
                    $value
                );
            }

            if (
                is_string($value)
                || is_int($value)
                || is_float($value)
            ) {
                return CarbonImmutable::parse(
                    (string) $value
                );
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function dateOrNow(
        mixed $value
    ): CarbonImmutable {
        return $this->dateValue(
            $value
        )
            ?? CarbonImmutable::now();
    }
}
