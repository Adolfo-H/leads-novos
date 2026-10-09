<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtros de CRM da tela Leads V15.
 *
 * Não altera os status sincronizados pelo HubSpot. Usamos apenas evidências
 * guardadas no banco local e nunca consideramos sync/checked_at uma interação.
 *
 * Prioridade quando as situações se sobrepõem: cliente > tarefa pendente >
 * novo > conhecido (<=30 dias) > reprospecção (>=90 dias).
 * Entre 31 e 89 dias, registros sem outra condição ficam apenas em "Todos".
 */
final class LeadCrmSituationService
{
    public const SITUATIONS = [
        'new',
        'known',
        'client',
        'reprospecting',
        'waiting',
    ];

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function apply(Builder $query, string $situation): Builder
    {
        if ($situation === 'client') {
            return $this->client($query);
        }

        if ($situation === 'waiting') {
            return $this->waiting($this->notClient($query));
        }

        if ($situation === 'new') {
            return $this->newLead($query);
        }

        if ($situation === 'known') {
            $this->withoutPendingTasks($this->notClient($query));
            $this->withHubSpot($query);

            return $query->where(function ($recent): void {
                $this->withMovement($recent, '>=', now()->subDays(30));
            });
        }

        if ($situation === 'reprospecting') {
            $this->withoutPendingTasks($this->notClient($query));
            $this->withHubSpot($query);

            // Não marcar como 90 dias sem contato se não houver uma data
            // de movimentação confiável.
            return $query
                ->where(function ($old): void {
                    $this->withMovement($old, '<=', now()->subDays(90));
                })
                ->where(function ($noRecent): void {
                    $cutoff = now()->subDays(90);
                    $noRecent
                        ->where(function ($work) use ($cutoff): void {
                            $work->whereNull('work.last_activity_at')
                                ->orWhere('work.last_activity_at', '<=', $cutoff);
                        })
                        ->whereDoesntHave('crmCheck', fn ($crm) => $crm
                            ->where('last_contacted_at', '>', $cutoff))
                        ->whereDoesntHave('hubSpotCompanies', fn ($hub) => $hub
                            ->where('last_activity_at', '>', $cutoff))
                        ->whereDoesntHave('hubSpotCompanies.commercialDeals', fn ($deal) => $deal
                            ->where(function ($activity) use ($cutoff): void {
                                $activity->where('hubspot_deals.last_activity_at', '>', $cutoff)
                                    ->orWhere('hubspot_deals.closed_at', '>', $cutoff);
                            }))
                        ->whereDoesntHave('hubSpotCompanies.activities', fn ($activity) => $activity
                            ->where('hubspot_activities.is_deleted', false)
                            ->where('hubspot_activities.occurred_at', '>', $cutoff));
                });
        }

        // Não ignorar silenciosamente filtros inválidos vindos de URL.
        return $query->whereRaw('1 = 0');
    }

    /**
     * Tarefas vencidas até o fim do dia: usa o espelho, não um prazo legado.
     *
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public function dueThroughToday(Builder $query): Builder
    {
        return $this->dueInMirror($query, today()->addDay());
    }

    /** @param Builder<Company> $query
     * @return Builder<Company>
     */
    public function overdue(Builder $query): Builder
    {
        return $this->dueInMirror($query, now());
    }

    /** @param Builder<Company> $query
     * @return Builder<Company>
     */
    public function dueForAuthenticatedUser(Builder $query): Builder
    {
        return $this->dueForAuthenticatedUserPeriod($query, null, today()->addDay());
    }

    /** @param Builder<Company> $query
     * @return Builder<Company>
     */
    public function dueForAuthenticatedUserPeriod(
        Builder $query,
        ?\DateTimeInterface $from,
        \DateTimeInterface $until,
    ): Builder {
        $user = auth()->user();
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $owners = array_values(array_unique(array_filter([
            trim((string) $user->name),
            trim((string) $user->hubspot_owner_id),
        ], static fn (string $owner): bool => $owner !== '')));

        if ($owners === []) {
            return $query->whereRaw('1 = 0');
        }

        return $this->dueInMirror($query, $until, $from, $owners, false);
    }

    /**
     * Regra compartilhada. O resumo legado só participa se apontar
     * para uma tarefa realmente aberta no espelho do HubSpot.
     *
     * @param  Builder<Company>  $query
     * @param  list<string>  $owners
     * @return Builder<Company>
     */
    private function dueInMirror(
        Builder $query,
        \DateTimeInterface $until,
        ?\DateTimeInterface $from = null,
        array $owners = [],
        bool $includeSnapshot = true,
    ): Builder {
        $filter = static function ($task) use ($until, $from, $owners): void {
            $task->dueInPeriod($until, $from, $owners);
        };

        // A associação comercial não deve propagar um CNPJ entre empresas.
        // Conserva os mesmos bloqueios do indicador anterior do Dashboard.
        $trustedCompany = static function ($hub): void {
            $hub->where(function ($source): void {
                $source->whereNull('hubspot_companies.match_source')
                    ->orWhere(function ($safe): void {
                        $safe->whereNotIn('hubspot_companies.match_source', [
                            'hubspot_related_explicit_cnpj',
                            'existing_company_unique_domain',
                        ])
                            ->whereRaw('LOWER(hubspot_companies.match_source) NOT LIKE ?', ['hubspot_related_%'])
                            ->whereRaw('LOWER(hubspot_companies.match_source) NOT LIKE ?', ['%inherited_cnpj%'])
                            ->whereRaw('LOWER(hubspot_companies.match_source) NOT LIKE ?', ['%propagated_cnpj%']);
                    });
            });
        };

        return $query->where(function (Builder $due) use (
            $filter, $includeSnapshot, $trustedCompany
        ): void {
            $due->whereHas('hubSpotCompanies', function ($hub) use ($filter, $trustedCompany): void {
                $trustedCompany($hub);
                $hub->whereHas('tasks', $filter);
            })
                ->orWhereHas('hubSpotCompanies', function ($hub) use ($filter, $trustedCompany): void {
                    $trustedCompany($hub);
                    $hub->whereHas('commercialDeals.tasks', $filter);
                });

            if ($includeSnapshot) {
                $due->orWhereHas('hubSpotLead.hubSpotTask', $filter);
            }
        });
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    private function newLead(Builder $query): Builder
    {
        $this->withoutPendingTasks($query);

        return $query
            ->where(function ($work): void {
                $work->whereNull('work.id')
                    ->orWhere('work.work_status', 'new');
            })
            ->whereNull('work.hubspot_company_id')
            ->whereNull('work.hubspot_deal_id')
            ->whereNull('work.last_activity_at')
            ->whereDoesntHave('hubSpotCompanies')
            ->whereDoesntHave('leadActivities')
            ->whereDoesntHave('leadWorkState', fn ($state) => $state
                ->whereNotNull('last_action_at'))
            ->where(function ($crm): void {
                $crm->whereDoesntHave('crmCheck')
                    ->orWhereHas('crmCheck', fn ($check) => $check
                        ->where('status', 'not_found')
                        ->where(function ($uncontacted): void {
                            $uncontacted->whereNull('last_contacted_at')
                                ->whereNull('external_id')
                                ->where(function ($count): void {
                                    $count->whereNull('contacted_count')
                                        ->orWhere('contacted_count', 0);
                                });
                        }));
            });
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    private function client(Builder $query): Builder
    {
        return $query->where(function ($client): void {
            $client->whereHas('crmCheck', fn ($crm) => $crm->where('status', 'client'))
                ->orWhereHas('hubSpotCompanies.commercialDeals', fn ($deal) => $deal
                    ->where('hubspot_deals.is_closed_won', true));
        });
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    private function notClient(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('crmCheck', fn ($crm) => $crm->where('status', 'client'))
            ->whereDoesntHave('hubSpotCompanies.commercialDeals', fn ($deal) => $deal
                ->where('hubspot_deals.is_closed_won', true));
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    private function waiting(Builder $query): Builder
    {
        return $query->where(function ($pending): void {
            $pending->where('work.open_task_count', '>', 0)
                ->orWhereHas('hubSpotCompanies.tasks', fn ($task) => $task
                    ->where('hubspot_tasks.is_open', true))
                ->orWhereHas('hubSpotCompanies.commercialDeals.tasks', fn ($task) => $task
                    ->where('hubspot_tasks.is_open', true));
        });
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    private function withoutPendingTasks(Builder $query): Builder
    {
        return $query
            ->where(function ($noWorkTask): void {
                $noWorkTask->whereNull('work.open_task_count')
                    ->orWhere('work.open_task_count', '<=', 0);
            })
            ->whereDoesntHave('hubSpotCompanies.tasks', fn ($task) => $task
                ->where('hubspot_tasks.is_open', true))
            ->whereDoesntHave('hubSpotCompanies.commercialDeals.tasks', fn ($task) => $task
                ->where('hubspot_tasks.is_open', true));
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    private function withHubSpot(Builder $query): Builder
    {
        return $query->where(function ($presence): void {
            $presence->whereNotNull('work.hubspot_company_id')
                ->orWhereNotNull('work.hubspot_deal_id')
                ->orWhereHas('hubSpotCompanies')
                ->orWhereHas('crmCheck', fn ($crm) => $crm
                    ->where('status', '!=', 'not_found')
                    ->whereNotNull('status'));
        });
    }

    /**
     * @param  Builder<Company>  $query
     */
    private function withMovement(Builder $query, string $operator, \DateTimeInterface $cutoff): void
    {
        $query->where('work.last_activity_at', $operator, $cutoff)
            ->orWhereHas('crmCheck', fn ($crm) => $crm
                ->where('last_contacted_at', $operator, $cutoff))
            ->orWhereHas('hubSpotCompanies', fn ($company) => $company
                ->where('last_activity_at', $operator, $cutoff))
            ->orWhereHas('hubSpotCompanies.commercialDeals', fn ($deal) => $deal
                ->where(function ($movement) use ($operator, $cutoff): void {
                    $movement->where('hubspot_deals.last_activity_at', $operator, $cutoff)
                        ->orWhere('hubspot_deals.closed_at', $operator, $cutoff);
                }))
            ->orWhereHas('hubSpotCompanies.activities', fn ($activity) => $activity
                ->where('hubspot_activities.is_deleted', false)
                ->where('hubspot_activities.occurred_at', $operator, $cutoff));
    }
}
