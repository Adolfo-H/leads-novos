<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyCrmCheck;
use App\Models\CompanyHubSpotLead;
use App\Models\CompanySdrScore;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class HubSpotRefreshDiffService
{
    /**
     * @return array<string, string|int>
     */
    /**
     * @return array<string, string|int>
     */
    public function snapshot(
        int $companyId
    ): array {
        $company =
            Company::query()
                ->with([
                    'crmCheck',
                    'sdrScore',
                    'hubSpotLead',
                ])
                ->findOrFail(
                    $companyId
                );

        $crmRelation =
            $company->getRelation(
                'crmCheck'
            );

        $crm =
            $crmRelation instanceof CompanyCrmCheck
                ? $crmRelation
                : null;

        $scoreRelation =
            $company->getRelation(
                'sdrScore'
            );

        $score =
            $scoreRelation instanceof CompanySdrScore
                ? $scoreRelation
                : null;

        $leadRelation =
            $company->getRelation(
                'hubSpotLead'
            );

        $lead =
            $leadRelation instanceof CompanyHubSpotLead
                ? $leadRelation
                : null;

        /*
         * Lemos os atributos como mixed.
         *
         * Além de refletir corretamente o comportamento
         * do Eloquent, isso evita o PHPStan assumir que
         * as relações HasOne sempre existem.
         */
        $crmMetadata =
            $crm instanceof CompanyCrmCheck
                ? $crm->getAttribute(
                    'metadata'
                )
                : null;

        $metadata =
            is_array(
                $crmMetadata
            )
                ? $crmMetadata
                : [];

        $stages =
            $this->strings(
                data_get(
                    $metadata,
                    'deal_summary.stages',
                    []
                )
            );

        sort(
            $stages
        );

        $crmExternalName =
            $crm instanceof CompanyCrmCheck
                ? $crm->getAttribute(
                    'external_name'
                )
                : null;

        $crmDealCount =
            $crm instanceof CompanyCrmCheck
                ? $crm->getAttribute(
                    'associated_deals_count'
                )
                : null;

        $leadOpenTasks =
            $lead instanceof CompanyHubSpotLead
                ? $lead->getAttribute(
                    'open_task_count'
                )
                : null;

        $crmContactedCount =
            $crm instanceof CompanyCrmCheck
                ? $crm->getAttribute(
                    'contacted_count'
                )
                : null;

        $scoreValue =
            $score instanceof CompanySdrScore
                ? $score->getAttribute(
                    'score'
                )
                : null;

        return [
            'crm_status' => $this->crmLabel(
                $crm instanceof CompanyCrmCheck
                    ? $crm->status
                    : null
            ),

            'crm_name' => trim(
                (string) (
                    is_scalar(
                        $crmExternalName
                    )
                        ? $crmExternalName
                        : ''
                )
            )
                ?: '—',

            'deal_count' => is_numeric(
                $crmDealCount
            )
                    ? (int) $crmDealCount
                    : 0,

            'deal_stages' => $stages !== []
                    ? implode(
                        ', ',
                        $stages
                    )
                    : '—',

            'commercial_status' => $this->commercialLabel(
                $lead instanceof CompanyHubSpotLead
                    ? $lead->commercial_status
                    : null
            ),

            'work_status' => $this->workLabel(
                $lead instanceof CompanyHubSpotLead
                    ? $lead->work_status
                    : null
            ),

            'open_tasks' => is_numeric(
                $leadOpenTasks
            )
                    ? (int) $leadOpenTasks
                    : 0,

            'next_task_subject' => $this->nextTaskSubject(
                $lead instanceof CompanyHubSpotLead
                    ? $lead->getAttribute(
                        'metadata'
                    )
                    : null
            ),

            'next_task_at' => $this->dateLabel(
                $lead instanceof CompanyHubSpotLead
                    ? $lead->last_task_due_at
                    : null
            ),

            'last_activity_at' => $this->dateLabel(
                $lead instanceof CompanyHubSpotLead
                    ? $lead->last_activity_at
                    : null
            ),

            'contacted_count' => is_numeric(
                $crmContactedCount
            )
                    ? (int) $crmContactedCount
                    : 0,

            'last_contacted_at' => $this->dateLabel(
                $crm instanceof CompanyCrmCheck
                    ? $crm->last_contacted_at
                    : null
            ),

            'score' => is_numeric(
                $scoreValue
            )
                    ? (int) $scoreValue
                    : 0,

            'priority' => $this->priorityLabel(
                $score instanceof CompanySdrScore
                    ? $score->priority
                    : null
            ),
        ];
    }

    /**
     * @param  array<string, string|int>  $before
     * @param  array<string, string|int>  $after
     * @return list<array{
     *     key: string,
     *     label: string,
     *     before: string|int,
     *     after: string|int
     * }>
     */
    public function changes(
        array $before,
        array $after,
    ): array {
        $labels = [
            'crm_status' => 'Situação CRM',

            'crm_name' => 'Nome no CRM',

            'deal_count' => 'Negócios HubSpot',

            'deal_stages' => 'Etapas HubSpot',

            'commercial_status' => 'Situação comercial',

            'work_status' => 'Acompanhamento',

            'open_tasks' => 'Tarefas abertas',

            'next_task_subject' => 'Próxima tarefa',

            'next_task_at' => 'Prazo da tarefa',

            'last_activity_at' => 'Última atividade',

            'contacted_count' => 'Interações registradas',

            'last_contacted_at' => 'Último contato',

            'score' => 'Score',

            'priority' => 'Prioridade',
        ];

        $changes = [];

        foreach (
            $labels as $key => $label
        ) {
            $old =
                $before[$key]
                ?? '—';

            $new =
                $after[$key]
                ?? '—';

            if ($old === $new) {
                continue;
            }

            $changes[] = [
                'key' => $key,

                'label' => $label,

                'before' => $old,

                'after' => $new,
            ];
        }

        return $changes;
    }

    private function crmLabel(
        ?string $status
    ): string {
        return match ($status) {
            'client' => 'Cliente',

            'opportunity' => 'Oportunidade',

            'prospected' => 'Reprospecção',

            'known' => 'Conhecido',

            'not_found' => 'Novo',

            default => '—',
        };
    }

    private function commercialLabel(
        ?string $status
    ): string {
        return match ($status) {
            'new' => 'Novo',

            'known' => 'Conhecido',

            'client' => 'Cliente',

            'reprospecting' => 'Reprospecção',

            default => '—',
        };
    }

    private function workLabel(
        ?string $status
    ): string {
        return match ($status) {
            'new' => 'Novo',

            'contacting' => 'Em contato',

            'waiting' => 'Aguardando retorno',

            'future' => 'Oportunidade futura',

            'reprospecting' => 'Reprospecção',

            'refused' => 'Recusou',

            'converted' => 'Convertido',

            'discarded' => 'Descartado',

            default => '—',
        };
    }

    private function priorityLabel(
        ?string $priority
    ): string {
        return match ($priority) {
            'high',
            'very_high' => 'Alta',

            'medium' => 'Média',

            'low' => 'Baixa',

            'blocked' => 'Bloqueada',

            default => '—',
        };
    }

    /**
     * @return list<string>
     */
    private function strings(
        mixed $value
    ): array {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $item =
                trim(
                    (string) $item
                );

            if ($item !== '') {
                $result[] =
                    $item;
            }
        }

        return array_values(
            array_unique(
                $result
            )
        );
    }

    private function nextTaskSubject(
        mixed $metadata
    ): string {
        $tasks =
            data_get(
                is_array($metadata)
                    ? $metadata
                    : [],
                'hubspot_status.open_tasks',
                []
            );

        if (! is_array($tasks)) {
            return '—';
        }

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            $subject =
                trim(
                    (string) (
                        $task[
                            'subject'
                        ]
                        ?? ''
                    )
                );

            if ($subject !== '') {
                return $subject;
            }
        }

        return '—';
    }

    private function dateLabel(
        mixed $value
    ): string {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance(
                $value
            )->format(
                'd/m/Y H:i'
            );
        }

        return '—';
    }
}
