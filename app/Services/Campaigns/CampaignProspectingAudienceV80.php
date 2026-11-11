<?php

namespace App\Services\Campaigns;

use App\Services\Contacts\ReceitaContactChannels;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Prospecção B2B baseada em evidências existentes. Nunca dispara e-mails.
 *
 * A ausência de atividade no espelho não significa que nunca houve contato.
 * Identidades fiscais são relacionadas somente por company_id confiável;
 * nome e domínio não servem para propagar CNPJ entre empresas.
 */
final class CampaignProspectingAudienceV80
{
    /** @param array<string, mixed> $input
     * @return array{origin:string,days:int,mode:string,company:string,job:string,search:string}
     */
    public static function filters(array $input): array
    {
        $origin = is_string($input['origin'] ?? null) ? $input['origin'] : 'all';
        $mode = is_string($input['mode'] ?? null) ? $input['mode'] : 'all';
        $days = filter_var($input['days'] ?? 90, FILTER_VALIDATE_INT);

        return [
            'origin' => in_array($origin, ['all', 'hubspot', 'manual', 'receita'], true) ? $origin : 'all',
            'days' => in_array($days, [30, 60, 90, 120, 180, 365], true) ? (int) $days : 90,
            'mode' => in_array($mode, ['all', 'new', 'dormant'], true) ? $mode : 'all',
            'company' => self::clean($input['company'] ?? ''),
            'job' => self::clean($input['job'] ?? ''),
            'search' => self::clean($input['search'] ?? ''),
        ];
    }

    private static function clean(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function paginate(array $filters, int $page = 1, ?int $actorId = null): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, \stdClass> $result */
        $result = $this->query($filters, $actorId)->paginate(50, ['*'], 'page', max(1, $page));

        return $result;
    }

    /** @param array<string, mixed> $filters */
    public function query(array $filters, ?int $actorId = null): Builder
    {
        $filters = self::filters($filters);
        $cutoff = now('UTC')->subDays($filters['days']);
        $base = $this->sources($filters['origin']);
        $query = DB::query()->fromSub($base, 's')->select('s.*')
            ->whereNotNull('s.normalized_email')
            ->whereRaw("s.normalized_email LIKE '%@%.%'")
            ->whereRaw("s.normalized_email NOT LIKE '% %'")
            ->whereRaw("s.normalized_email NOT LIKE '%@%@%'")
            ->whereRaw('LENGTH(s.normalized_email) <= 254')
            ->where('s.company_link_status', '<>', 'ambiguous')
            ->where(static function (Builder $q) use ($cutoff): void {
                $q->whereNull('s.last_contact_at')->orWhere('s.last_contact_at', '<', $cutoff);
            });

        // EC_PROSPECTING_EXCLUDE_V83: exclusão privada do gestor, sem descadastro global.
        if ($actorId !== null) {
            $query->whereNotExists(static function (Builder $excluded) use ($actorId): void {
                $excluded->selectRaw('1')
                    ->from('email_campaign_prospecting_exclusions_v83 as x')
                    ->where('x.user_id', $actorId)
                    ->whereColumn('x.normalized_email', 's.normalized_email');
            });
        }

        if ($filters['mode'] === 'new') {
            $query->whereNull('s.last_contact_at');
        } elseif ($filters['mode'] === 'dormant') {
            $query->whereNotNull('s.last_contact_at');
        }
        foreach (['company' => 'company_name', 'job' => 'job_title', 'search' => 'contact_name'] as $filter => $column) {
            if ($filters[$filter] !== '') {
                $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters[$filter])).'%';
                $query->whereRaw("LOWER(COALESCE(s.{$column}, '')) LIKE ? ESCAPE '!'", [$needle]);
            }
        }

        // EC_CAMPAIGNS_UNIFIED_V90: nova prospecção nunca reutiliza e-mail aceito
        // pelo SMTP em outra campanha, mesmo após 90/180/365 dias. Follow-up
        // autorizado é um fluxo separado e não usa este seletor.
        $query->whereNotExists(static function (Builder $q): void {
            $q->selectRaw('1')->from('email_campaign_recipients as historical')
                ->whereColumn('historical.normalized_email', 's.normalized_email')
                ->whereIn('historical.status', ['sent', 'uncertain']);
        });
        // Bloqueios universais: descadastro, bounce permanente e disparo recente.
        $query->whereNotExists(static function (Builder $q): void {
            $q->selectRaw('1')->from('email_campaign_suppressions as sup')
                ->whereColumn('sup.normalized_email', 's.normalized_email');
        });
        $query->whereNotExists(static function (Builder $q) use ($cutoff): void {
            $q->selectRaw('1')->from('email_campaign_recipients as r')
                ->whereColumn('r.normalized_email', 's.normalized_email')
                ->where(static function (Builder $w) use ($cutoff): void {
                    $w->where(static function (Builder $recent) use ($cutoff): void {
                        $recent->whereIn('r.status', ['sent', 'sending', 'reserved', 'uncertain'])
                            ->where(static function (Builder $d) use ($cutoff): void {
                                $d->where('r.attempted_at', '>=', $cutoff)
                                    ->orWhere('r.sent_at', '>=', $cutoff)
                                    ->orWhere('r.reserved_at', '>=', $cutoff);
                            });
                    })->orWhere('r.replied_at', '>=', $cutoff);
                });
        });
        $query->whereNotExists(static function (Builder $q) use ($cutoff): void {
            $q->selectRaw('1')->from('email_campaign_replies as rep')
                ->whereRaw('LOWER(TRIM(rep.from_email)) = s.normalized_email')
                ->where('rep.received_at', '>=', $cutoff);
        });
        $query->whereNotExists(static function (Builder $q): void {
            $q->selectRaw('1')->from('email_campaign_recipients as r')
                ->join('email_campaigns as ec', 'ec.id', '=', 'r.email_campaign_id')
                ->whereColumn('r.normalized_email', 's.normalized_email')
                ->whereIn('r.status', ['pending', 'reserved', 'sending', 'uncertain'])
                ->whereIn('ec.status', ['approved', 'running']);
        });

        // O MESMO e-mail pode existir na Receita e no HubSpot: um vínculo CRM
        // recente ou de cliente bloqueia o e-mail em TODAS as origens.
        $query->whereNotExists(static function (Builder $q) use ($cutoff): void {
            $q->selectRaw('1')->from('hubspot_contacts as h')
                ->whereRaw('LOWER(TRIM(h.email)) = s.normalized_email')
                ->where(static function (Builder $w) use ($cutoff): void {
                    $w->whereIn(DB::raw('LOWER(TRIM(h.lifecycle_stage))'), ['customer', 'client', 'cliente', 'evangelist'])
                        ->orWhere('h.last_activity_at', '>=', $cutoff)
                        ->orWhereExists(static function (Builder $d) use ($cutoff): void {
                            $d->selectRaw('1')->from('hubspot_deal_contact as dc')
                                ->join('hubspot_deals as deal', 'deal.id', '=', 'dc.hubspot_deal_id')
                                ->whereColumn('dc.hubspot_contact_id', 'h.id')
                                ->where(static function (Builder $x) use ($cutoff): void {
                                    $x->where('deal.is_closed', false)->orWhere('deal.is_closed_won', true)
                                        ->orWhere('deal.last_activity_at', '>=', $cutoff);
                                });
                        })
                        ->orWhereExists(static function (Builder $t) use ($cutoff): void {
                            $t->selectRaw('1')->from('hubspot_contact_task as ct')
                                ->join('hubspot_tasks as task', 'task.id', '=', 'ct.hubspot_task_id')
                                ->whereColumn('ct.hubspot_contact_id', 'h.id')->where(static function (Builder $state) use ($cutoff): void {
                                    $state->where(static function (Builder $open): void {
                                        $open->where('task.is_open', true)->whereNull('task.completed_at');
                                    })->orWhere('task.completed_at', '>=', $cutoff);
                                });
                        })
                        // O contato pode ser associado à Company apenas por um negócio.
                        // A empresa cliente/ativa desse negócio também bloqueia prospecção.
                        ->orWhereExists(static function (Builder $companyViaDeal) use ($cutoff): void {
                            $companyViaDeal->selectRaw('1')->from('hubspot_deal_contact as dc_company')
                                ->join('hubspot_company_deal as cd_company', 'cd_company.hubspot_deal_id', '=', 'dc_company.hubspot_deal_id')
                                ->join('hubspot_companies as co_deal', 'co_deal.id', '=', 'cd_company.hubspot_company_id')
                                ->whereColumn('dc_company.hubspot_contact_id', 'h.id')
                                ->where(static function (Builder $state) use ($cutoff): void {
                                    $state->whereIn(DB::raw('LOWER(TRIM(co_deal.lifecycle_stage))'), ['customer', 'client', 'cliente'])
                                        ->orWhere('co_deal.last_activity_at', '>=', $cutoff)
                                        ->orWhereExists(static function (Builder $local): void {
                                            $local->selectRaw('1')->from('company_crm_checks as crm_deal')
                                                ->whereColumn('crm_deal.company_id', 'co_deal.company_id')
                                                ->where('crm_deal.status', 'client')
                                                ->whereRaw("LOWER(TRIM(COALESCE(co_deal.match_source, ''))) NOT IN ('hubspot_related_explicit_cnpj', 'existing_company_unique_domain')")
                                                ->whereRaw("LOWER(TRIM(COALESCE(co_deal.match_source, ''))) NOT LIKE 'hubspot!_related!_%' ESCAPE '!'")
                                                ->whereRaw("LOWER(TRIM(COALESCE(co_deal.match_source, ''))) NOT LIKE '%inherited!_cnpj%' ESCAPE '!'")
                                                ->whereRaw("LOWER(TRIM(COALESCE(co_deal.match_source, ''))) NOT LIKE '%propagated!_cnpj%' ESCAPE '!'");
                                        });
                                });
                        })
                        ->orWhereExists(static function (Builder $hc) use ($cutoff): void {
                            $hc->selectRaw('1')->from('hubspot_company_contact as cc')
                                ->join('hubspot_companies as co', 'co.id', '=', 'cc.hubspot_company_id')
                                ->whereColumn('cc.hubspot_contact_id', 'h.id')
                                ->where(static function (Builder $cx) use ($cutoff): void {
                                    $cx->whereIn(DB::raw('LOWER(TRIM(co.lifecycle_stage))'), ['customer', 'client', 'cliente'])
                                        ->orWhere('co.last_activity_at', '>=', $cutoff)
                                        ->orWhereExists(static function (Builder $local): void {
                                            $local->selectRaw('1')->from('company_crm_checks as crm')
                                                ->whereColumn('crm.company_id', 'co.company_id')
                                                ->where('crm.status', 'client');
                                        })
                                        ->orWhereExists(static function (Builder $registry): void {
                                            $registry->selectRaw('1')->from('companies as local')
                                                ->join('customer_registry_entries as client', static function ($join): void {
                                                    $join->where('client.enabled', true)
                                                        ->where(static function (Builder $same): void {
                                                            $same->where(static function (Builder $byRoot): void {
                                                                $byRoot->whereNotNull('client.cnpj_root')
                                                                    ->whereColumn('client.cnpj_root', 'local.cnpj_root');
                                                            })->orWhere(static function (Builder $byName): void {
                                                                $byName->whereNull('client.cnpj_root')
                                                                    ->whereColumn('client.normalized_name', 'local.normalized_name')
                                                                    ->whereRaw("COALESCE(local.normalized_name, '') <> ''");
                                                            });
                                                        });
                                                })->whereColumn('local.id', 'co.company_id');
                                        })
                                        ->orWhereExists(static function (Builder $d): void {
                                            $d->selectRaw('1')->from('hubspot_company_deal as cd')
                                                ->join('hubspot_deals as deal', 'deal.id', '=', 'cd.hubspot_deal_id')
                                                ->whereColumn('cd.hubspot_company_id', 'co.id')
                                                ->where(static function (Builder $x): void {
                                                    $x->where('deal.is_closed', false)->orWhere('deal.is_closed_won', true);
                                                });
                                        })
                                        ->orWhereExists(static function (Builder $a) use ($cutoff): void {
                                            $a->selectRaw('1')->from('hubspot_activity_company as ac')
                                                ->join('hubspot_activities as act', 'act.id', '=', 'ac.hubspot_activity_id')
                                                ->whereColumn('ac.hubspot_company_id', 'co.id')
                                                ->where('act.is_deleted', false)->where('act.occurred_at', '>=', $cutoff);
                                        })
                                        ->orWhereExists(static function (Builder $t): void {
                                            $t->selectRaw('1')->from('hubspot_company_task as ct')
                                                ->join('hubspot_tasks as task', 'task.id', '=', 'ct.hubspot_task_id')
                                                ->whereColumn('ct.hubspot_company_id', 'co.id')
                                                ->where('task.is_open', true)->whereNull('task.completed_at');
                                        });
                                });
                        });
                });
        });

        // O mesmo e-mail também pode aparecer em duas fichas manuais.
        // Se uma delas pertence a cliente ou tem contato recente, o endereço inteiro é bloqueado.
        $query->whereNotExists(static function (Builder $q) use ($cutoff): void {
            $q->selectRaw('1')->from('commercial_contacts as other')
                ->join('company_crm_checks as crm', 'crm.company_id', '=', 'other.company_id')
                ->whereRaw('LOWER(TRIM(other.email)) = s.normalized_email')
                ->where(static function (Builder $w) use ($cutoff): void {
                    $w->where('crm.status', 'client')->orWhere('crm.last_contacted_at', '>=', $cutoff);
                });
        });

        // CRM local por ID fiscal (não por nome/domínio inferidos).
        $query->whereNotExists(static function (Builder $q): void {
            $q->selectRaw('1')->from('company_crm_checks as crm')
                ->whereColumn('crm.company_id', 's.company_id')->where('crm.status', 'client');
        });
        $query->whereNotExists(static function (Builder $q): void {
            $q->selectRaw('1')->from('companies as c')
                ->join('customer_registry_entries as cr', static function ($join): void {
                    $join->where('cr.enabled', true)
                        ->where(static function (Builder $x): void {
                            $x->where(static function (Builder $viaCnpj): void {
                                $viaCnpj->whereNotNull('cr.cnpj_root')
                                    ->whereColumn('cr.cnpj_root', 'c.cnpj_root');
                            })->orWhere(static function (Builder $viaName): void {
                                $viaName->whereNull('cr.cnpj_root')
                                    ->whereColumn('cr.normalized_name', 'c.normalized_name')
                                    ->whereRaw("COALESCE(c.normalized_name, '') <> ''");
                            });
                        });
                })->whereColumn('c.id', 's.company_id');
        });
        $query->whereNotExists(static function (Builder $q) use ($cutoff): void {
            $q->selectRaw('1')->from('company_crm_checks as crm')
                ->whereColumn('crm.company_id', 's.company_id')
                ->where('crm.last_contacted_at', '>=', $cutoff);
        });
        $query->whereNotExists(static function (Builder $q) use ($cutoff): void {
            $q->selectRaw('1')->from('company_lead_activities as act')
                ->whereColumn('act.company_id', 's.company_id')
                ->where('act.is_deleted', false)->where('act.occurred_at', '>=', $cutoff);
        });
        $query->whereNotExists(static function (Builder $q) use ($cutoff): void {
            $q->selectRaw('1')->from('company_lead_work_states as ws')
                ->whereColumn('ws.company_id', 's.company_id')
                ->where('ws.last_action_at', '>=', $cutoff);
        });
        $query->whereNotExists(static function (Builder $q) use ($cutoff): void {
            $q->selectRaw('1')->from('company_hubspot_leads as hl')
                ->whereColumn('hl.company_id', 's.company_id')
                ->where(static function (Builder $w) use ($cutoff): void {
                    $w->where('hl.last_activity_at', '>=', $cutoff)
                        ->orWhereIn(DB::raw('LOWER(TRIM(hl.commercial_status))'), ['client', 'customer', 'cliente']);
                });
        });
        $query->whereNotExists(static function (Builder $q) use ($cutoff): void {
            $q->selectRaw('1')->from('hubspot_companies as co')
                ->whereColumn('co.company_id', 's.company_id')
                ->where(static function (Builder $trust): void {
                    $trust->whereNull('co.match_source')->orWhere(static function (Builder $notUnsafe): void {
                        $notUnsafe->whereNotIn(DB::raw('LOWER(TRIM(co.match_source))'), [
                            'hubspot_related_explicit_cnpj', 'existing_company_unique_domain',
                        ])->whereRaw("LOWER(TRIM(co.match_source)) NOT LIKE 'hubspot!_related!_%' ESCAPE '!'")
                            ->whereRaw("LOWER(TRIM(co.match_source)) NOT LIKE '%inherited!_cnpj%' ESCAPE '!'")
                            ->whereRaw("LOWER(TRIM(co.match_source)) NOT LIKE '%propagated!_cnpj%' ESCAPE '!'");
                    });
                })
                ->where(static function (Builder $w) use ($cutoff): void {
                    $w->whereIn(DB::raw('LOWER(TRIM(co.lifecycle_stage))'), ['customer', 'client', 'cliente'])
                        ->orWhere('co.last_activity_at', '>=', $cutoff)
                        ->orWhereExists(static function (Builder $d): void {
                            $d->selectRaw('1')->from('hubspot_company_deal as cd')
                                ->join('hubspot_deals as deal', 'deal.id', '=', 'cd.hubspot_deal_id')
                                ->whereColumn('cd.hubspot_company_id', 'co.id')
                                ->where(static function (Builder $x): void {
                                    $x->where('deal.is_closed', false)->orWhere('deal.is_closed_won', true);
                                });
                        })
                        ->orWhereExists(static function (Builder $a) use ($cutoff): void {
                            $a->selectRaw('1')->from('hubspot_activity_company as ac')
                                ->join('hubspot_activities as act', 'act.id', '=', 'ac.hubspot_activity_id')
                                ->whereColumn('ac.hubspot_company_id', 'co.id')
                                ->where('act.is_deleted', false)->where('act.occurred_at', '>=', $cutoff);
                        })
                        ->orWhereExists(static function (Builder $t): void {
                            $t->selectRaw('1')->from('hubspot_company_task as ct')
                                ->join('hubspot_tasks as task', 'task.id', '=', 'ct.hubspot_task_id')
                                ->whereColumn('ct.hubspot_company_id', 'co.id')
                                ->where('task.is_open', true)->whereNull('task.completed_at');
                        });
                });
        });

        // Consolidar por endereço, dando prioridade à pessoa HubSpot.
        // Mantém a seleção paginada; evita carregar milhares de e-mails no PHP.
        $ranked = DB::query()->fromSub($query, 'candidate')
            ->select('candidate.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY candidate.normalized_email ORDER BY candidate.source_priority, candidate.source_key) AS duplicate_rank');

        return DB::query()->fromSub($ranked, 'eligible')->where('eligible.duplicate_rank', 1)
            ->orderBy('eligible.source_priority')->orderBy('eligible.source_key');
    }

    /**
     * Resolve uma empresa comercial do HubSpot sem inventar vínculo fiscal.
     * Prioridade: vínculo primário contato/empresa, vínculo único contato/empresa,
     * negócio com empresa primária ou única, texto informado no contato.
     * Associação múltipla sem primária única fica sinalizada como ambígua.
     */
    private function sources(string $origin): Builder
    {
        $queries = [];
        if (in_array($origin, ['all', 'hubspot'], true)) {
            // Uma agregação por contato: impede linhas duplicadas e evita N+1.
            $directCompanies = DB::table('hubspot_company_contact as cc')
                ->select('cc.hubspot_contact_id')
                ->selectRaw('COUNT(*) AS linked_total')
                ->selectRaw('SUM(CASE WHEN cc.is_primary THEN 1 ELSE 0 END) AS primary_total')
                ->selectRaw('MAX(CASE WHEN cc.is_primary THEN cc.hubspot_company_id ELSE NULL END) AS primary_company_id')
                ->selectRaw('MAX(cc.hubspot_company_id) AS only_company_id')
                ->groupBy('cc.hubspot_contact_id');

            // Um contato pode ter negócios com várias Companies. Só escolhemos
            // quando existe uma primária única ou uma única Company distinta.
            $dealCompanies = DB::table('hubspot_deal_contact as dc')
                ->join('hubspot_company_deal as cd', 'cd.hubspot_deal_id', '=', 'dc.hubspot_deal_id')
                ->select('dc.hubspot_contact_id')
                ->selectRaw('COUNT(DISTINCT cd.hubspot_company_id) AS linked_total')
                ->selectRaw('COUNT(DISTINCT CASE WHEN cd.is_primary THEN cd.hubspot_company_id ELSE NULL END) AS primary_total')
                ->selectRaw('MAX(CASE WHEN cd.is_primary THEN cd.hubspot_company_id ELSE NULL END) AS primary_company_id')
                ->selectRaw('MAX(cd.hubspot_company_id) AS only_company_id')
                ->groupBy('dc.hubspot_contact_id');

            $directId = 'CASE WHEN direct_co.primary_total = 1 THEN direct_co.primary_company_id '
                .'WHEN direct_co.linked_total = 1 THEN direct_co.only_company_id ELSE NULL END';
            $dealId = 'CASE WHEN deal_co.primary_total = 1 THEN deal_co.primary_company_id '
                .'WHEN deal_co.linked_total = 1 THEN deal_co.only_company_id ELSE NULL END';
            // Uma associação direta ambígua não pode ser substituída silenciosamente
            // por outra inferida de negócios.
            $resolvedId = 'CASE WHEN COALESCE(direct_co.linked_total, 0) > 0 '
                .'THEN ('.$directId.') ELSE ('.$dealId.') END';
            $trustedMatch = "LOWER(TRIM(COALESCE(associated_co.match_source, '')))";
            $fiscalTrust = 'associated_co.company_id IS NOT NULL '
                ."AND {$trustedMatch} NOT IN ('hubspot_related_explicit_cnpj', 'existing_company_unique_domain') "
                ."AND {$trustedMatch} NOT LIKE 'hubspot!_related!_%' ESCAPE '!' "
                ."AND {$trustedMatch} NOT LIKE '%inherited!_cnpj%' ESCAPE '!' "
                ."AND {$trustedMatch} NOT LIKE '%propagated!_cnpj%' ESCAPE '!'";
            $companyName = "COALESCE(NULLIF(TRIM(associated_co.name), ''), "
                ."NULLIF(TRIM(associated_co.matched_company_name), ''), "
                ."NULLIF(TRIM(h.company_name), ''))";
            $associationKind = 'CASE '
                ."WHEN associated_co.id IS NOT NULL AND COALESCE(direct_co.linked_total, 0) > 0 THEN 'contact_company' "
                ."WHEN associated_co.id IS NOT NULL THEN 'deal_company' "
                ."WHEN COALESCE(direct_co.linked_total, 0) > 0 OR COALESCE(deal_co.linked_total, 0) > 0 THEN 'ambiguous' "
                ."WHEN NULLIF(TRIM(h.company_name), '') IS NOT NULL THEN 'contact_text' "
                ."ELSE 'unlinked' END";

            $queries[] = DB::table('hubspot_contacts as h')
                ->leftJoinSub($directCompanies, 'direct_co', 'direct_co.hubspot_contact_id', '=', 'h.id')
                ->leftJoinSub($dealCompanies, 'deal_co', 'deal_co.hubspot_contact_id', '=', 'h.id')
                ->leftJoin('hubspot_companies as associated_co', static function (JoinClause $join) use ($resolvedId): void {
                    $join->on('associated_co.id', '=', DB::raw($resolvedId));
                })
                ->selectRaw("('hubspot:' || CAST(h.hubspot_id AS VARCHAR)) AS source_key")
                ->selectRaw('LOWER(TRIM(h.email)) AS normalized_email')
                ->selectRaw("TRIM(COALESCE(h.first_name, '') || ' ' || COALESCE(h.last_name, '')) AS contact_name")
                ->selectRaw($companyName.' AS company_name, h.job_title AS job_title')
                ->selectRaw("'hubspot' AS origin, 1 AS source_priority")
                ->selectRaw('h.id AS hubspot_contact_id')
                ->selectRaw('CASE WHEN '.$fiscalTrust.' THEN associated_co.company_id ELSE NULL END AS company_id')
                ->selectRaw('h.last_activity_at AS last_contact_at, h.lifecycle_stage AS lifecycle_stage')
                ->selectRaw($associationKind.' AS company_link_status')
                ->whereNotNull('h.hubspot_id')->whereNotNull('h.email')->where('h.email', '<>', '');
        }
        if (in_array($origin, ['all', 'manual'], true)) {
            $queries[] = DB::table('commercial_contacts as m')
                ->leftJoin('companies as c', 'c.id', '=', 'm.company_id')
                ->leftJoin('company_crm_checks as crm', 'crm.company_id', '=', 'c.id')
                ->selectRaw("('manual:' || CAST(m.id AS VARCHAR)) AS source_key")
                ->selectRaw('LOWER(TRIM(m.email)) AS normalized_email')
                ->selectRaw('m.name AS contact_name, c.corporate_name AS company_name, m.job_title AS job_title')
                ->selectRaw("'manual' AS origin, 2 AS source_priority")
                ->selectRaw('NULL AS hubspot_contact_id, m.company_id AS company_id')
                ->selectRaw('crm.last_contacted_at AS last_contact_at, NULL AS lifecycle_stage')
                ->selectRaw("'local_company' AS company_link_status")
                ->whereNotNull('m.email')->where('m.email', '<>', '');
        }
        if (in_array($origin, ['all', 'receita'], true)) {
            $queries[] = ReceitaContactChannels::withEmail()
                ->leftJoin('company_crm_checks as crm', 'crm.company_id', '=', 'rc.id')
                ->selectRaw("('receita:' || CAST(e.uuid AS VARCHAR)) AS source_key")
                ->selectRaw('LOWER(TRIM(e.email)) AS normalized_email')
                ->selectRaw("'Equipe' AS contact_name, rc.corporate_name AS company_name, 'Canal cadastral da Receita' AS job_title")
                ->selectRaw("'receita' AS origin, 3 AS source_priority")
                ->selectRaw('NULL AS hubspot_contact_id, e.company_id AS company_id')
                ->selectRaw('crm.last_contacted_at AS last_contact_at, NULL AS lifecycle_stage')
                ->selectRaw("'receita_company' AS company_link_status");
        }
        $base = array_shift($queries);
        assert($base instanceof Builder);
        foreach ($queries as $query) {
            $base->unionAll($query);
        }

        return $base;
    }
}
