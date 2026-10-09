<?php

namespace App\Services;

use App\Models\Company;
use App\Models\HubSpotCompany;
use App\Models\HubSpotTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** Dashboard V23: shared, read-only drilldown counts for leads and risks. */
final class DashboardLeadMetricsV23Service
{
    /** @return array{leads:int,unassigned:int,with_deal:int,overdue:int,stale90:int,largest:list<array{id:int,name:string,establishments:int,emails:int,phones:int}>} */
    public function snapshot(): array
    {
        return $this->snapshotWithCompanyIds()['summary'];
    }

    /**
     * DASHBOARD_SNAPSHOT_BUNDLE_V244:
     * Calcula os conjuntos uma vez e entrega ao template o mesmo recorte
     * utilizado no resumo. Sem cache entre requisições nem usuários.
     *
     * @return array{
     *     summary: array{leads:int,unassigned:int,with_deal:int,overdue:int,stale90:int,largest:list<array{id:int,name:string,establishments:int,emails:int,phones:int}>},
     *     lead_ids: list<int>,
     *     with_deal_ids: list<int>,
     *     stale_ids: list<int>
     * }
     */
    public function snapshotWithCompanyIds(): array
    {
        $ids = $this->leadCompanyIds();

        $assigned = DB::table('company_lead_work_states')
            ->whereIn('company_id', $ids)
            ->whereNotNull('assigned_user_id')
            ->pluck('company_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        $assignedLookup = array_fill_keys($assigned, true);
        $unassigned = count(array_filter(
            $ids,
            static fn (int $id): bool => ! isset($assignedLookup[$id])
        ));

        $withDealIds = $this->withDealCompanyIds($ids);
        $overdueIds = $this->overdueCompanyIds($ids);
        $staleIds = $this->staleCompanyIds($ids);

        return [
            'summary' => [
                'leads' => count($ids),
                'unassigned' => $unassigned,
                'with_deal' => count($withDealIds),
                'overdue' => count($overdueIds),
                'stale90' => count($staleIds),
                'largest' => $this->largestCompanies($ids),
            ],
            'lead_ids' => $ids,
            'with_deal_ids' => $withDealIds,
            'stale_ids' => $staleIds,
        ];
    }

    /** @return list<int> */
    public function leadCompanyIds(): array
    {
        return array_values($this->baseQuery()->pluck('companies.id')->map(static fn (mixed $v): int => (int) $v)->all());
    }

    /**
     * @param  list<int>|null  $leadIds
     * @return list<int>
     */
    public function withDealCompanyIds(?array $leadIds = null): array
    {
        $ids = $leadIds ?? $this->leadCompanyIds();
        $snapshotIds = DB::table('company_hubspot_leads')
            ->whereIn('company_id', $ids)->whereNotNull('hubspot_deal_id')->pluck('company_id')->all();
        $mirrorIds = DB::table('hubspot_companies as hc')
            ->join('hubspot_company_deal as hcd', 'hc.id', '=', 'hcd.hubspot_company_id')
            ->whereIn('hc.company_id', $ids)
            ->whereNotNull('hc.company_id')
            // DASHBOARD_DEAL_OWNERSHIP_GROUPED_V246: agregacao unica por negocio.
            // Regra: 1 Company => aceita; varias => Primary unica.
            ->joinSub(
                $this->dealOwnershipSummary(),
                'ec_owner_counts',
                static function (JoinClause $join): void {
                    $join->on('ec_owner_counts.hubspot_deal_id', '=', 'hcd.hubspot_deal_id');
                }
            )
            ->where(static function (Builder $owner): void {
                $owner->where('ec_owner_counts.company_count', 1)
                    ->orWhere(static function (Builder $primary): void {
                        $primary->where('hcd.is_primary', true)
                            ->where('ec_owner_counts.primary_count', 1);
                    });
            })
            // FISCAL_READERS_CENTRAL_POLICY_V16_2 - fonte unica: HubSpotCompany::trustedFiscalLink().
            ->whereIn('hc.id', HubSpotCompany::query()->trustedFiscalLink()->select('id'))
            ->pluck('hc.company_id')->all();

        return array_values(array_unique(array_map('intval', [...$snapshotIds, ...$mirrorIds])));
    }

    /**
     * @param  list<int>|null  $leadIds
     * @return list<int>
     */
    public function overdueCompanyIds(?array $leadIds = null): array
    {
        $ids = $leadIds ?? $this->leadCompanyIds();
        if ($ids === []) {
            return [];
        }

        // DASHBOARD_OVERDUE_FAST_PATH_V247
        // Se nao existe nenhuma tarefa globalmente aberta e vencida,
        // nenhuma empresa pode ter follow-up atrasado no espelho.
        // Havendo qualquer tarefa vencida, usa a regra compartilhada
        // integral (associacoes diretas, por Deal e snapshot).
        if (! HubSpotTask::query()->dueInPeriod(now())->exists()) {
            return [];
        }

        $query = app(LeadCrmSituationService::class)->overdue(
            Company::query()->whereIn('companies.id', $ids)
        );

        return array_values($query->pluck('companies.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * 90 days without any recorded HubSpot commercial interaction. No date =
     * unknown and does not count as idle. Latest timestamp wins.
     *
     * @param  list<int>|null  $leadIds
     * @return list<int>
     */
    public function staleCompanyIds(?array $leadIds = null): array
    {
        $ids = $leadIds ?? $this->leadCompanyIds();
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('companies as companies')
            ->leftJoin('company_hubspot_leads as work', 'work.company_id', '=', 'companies.id')
            ->leftJoin('company_crm_checks as crm', 'crm.company_id', '=', 'companies.id')
            ->whereIn('companies.id', $ids)
            ->get(['companies.id', 'work.last_activity_at', 'crm.last_contacted_at']);
        $remote = DB::table('hubspot_companies')
            ->whereIn('company_id', $ids)
            // DASHBOARD_STALE_ACTIVITY_ONLY_V14:
            // Editar o cadastro não comprova contato comercial.
            ->whereNotNull('last_activity_at')
            ->whereIn('id', HubSpotCompany::query()->trustedFiscalLink()->select('id'))
            ->select('company_id')
            ->selectRaw('MAX(last_activity_at) AS last_at')
            ->groupBy('company_id')->get()->keyBy('company_id');
        $deals = DB::table('hubspot_deals as d')
            ->join('hubspot_company_deal as link', 'link.hubspot_deal_id', '=', 'd.id')
            ->join('hubspot_companies as hc', 'hc.id', '=', 'link.hubspot_company_id')
            ->whereIn('hc.company_id', $ids)
            // DASHBOARD_DEAL_OWNERSHIP_GROUPED_V246: agregacao unica por negocio.
            // Regra: 1 Company => aceita; varias => Primary unica.
            ->joinSub(
                $this->dealOwnershipSummary(),
                'ec_owner_counts',
                static function (JoinClause $join): void {
                    $join->on('ec_owner_counts.hubspot_deal_id', '=', 'link.hubspot_deal_id');
                }
            )
            ->where(static function (Builder $owner): void {
                $owner->where('ec_owner_counts.company_count', 1)
                    ->orWhere(static function (Builder $primary): void {
                        $primary->where('link.is_primary', true)
                            ->where('ec_owner_counts.primary_count', 1);
                    });
            })
            ->whereIn('hc.id', HubSpotCompany::query()->trustedFiscalLink()->select('id'))
            ->whereNotNull('d.last_activity_at')
            ->select('hc.company_id')
            ->selectRaw('MAX(d.last_activity_at) AS last_at')
            ->groupBy('hc.company_id')->get()->keyBy('company_id');
        $cutoff = now()->subDays(90);
        $stale = [];
        foreach ($rows as $row) {
            $remoteRow = $remote->get((int) $row->id);
            $remoteLast = is_object($remoteRow) ? ($remoteRow->last_at ?? null) : null;
            $dealRow = $deals->get((int) $row->id);
            $dealLast = is_object($dealRow) ? ($dealRow->last_at ?? null) : null;
            $candidateTimes = [$row->last_activity_at, $row->last_contacted_at, $remoteLast, $dealLast];
            $latest = null;
            foreach ($candidateTimes as $value) {
                if ($value === null || trim((string) $value) === '') {
                    continue;
                }
                try {
                    $date = CarbonImmutable::parse((string) $value);
                } catch (\Throwable) {
                    continue;
                }
                if ($latest === null || $date->greaterThan($latest)) {
                    $latest = $date;
                }
            }
            if ($latest !== null && $latest->lessThan($cutoff)) {
                $stale[] = (int) $row->id;
            }
        }

        return $stale;
    }

    /**
     * Conta as associacoes e as empresas Primary de todos os negocios
     * em uma unica passagem pela tabela pivot, em vez de subqueries
     * COUNT(*) executadas para cada vinculo encontrado.
     */
    private function dealOwnershipSummary(): Builder
    {
        return DB::table('hubspot_company_deal as ownership_links')
            ->select('ownership_links.hubspot_deal_id')
            ->selectRaw('COUNT(*) AS company_count')
            ->selectRaw('SUM(CASE WHEN ownership_links.is_primary = TRUE THEN 1 ELSE 0 END) AS primary_count')
            ->groupBy('ownership_links.hubspot_deal_id');
    }

    /**
     * @param  list<int>  $companyIds
     * @return list<array{id:int,name:string,establishments:int,emails:int,phones:int}>
     */
    private function largestCompanies(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }
        $rows = DB::table('companies as c')
            ->join('establishments as e', 'e.company_id', '=', 'c.id')
            ->whereIn('c.id', $companyIds)
            ->select(['c.id', 'c.corporate_name'])
            ->selectRaw('COUNT(e.id) AS establishments_total')
            ->selectRaw("SUM(CASE WHEN e.email IS NOT NULL AND TRIM(e.email) != '' THEN 1 ELSE 0 END) AS emails_total")
            ->selectRaw("SUM(CASE WHEN COALESCE(TRIM(e.phone_1), '') != '' OR COALESCE(TRIM(e.phone_2), '') != '' THEN 1 ELSE 0 END) AS phones_total")
            ->groupBy('c.id', 'c.corporate_name')
            ->orderByDesc('establishments_total')
            ->orderByDesc('emails_total')
            ->limit(5)->get();

        return array_values($rows->map(static fn (object $row): array => [
            'id' => (int) $row->id,
            'name' => (string) $row->corporate_name,
            'establishments' => (int) $row->establishments_total,
            'emails' => (int) $row->emails_total,
            'phones' => (int) $row->phones_total,
        ])->all());
    }

    private function baseQuery(): Builder
    {
        return DB::table('companies')
            ->leftJoin('company_sdr_scores as sdr', 'sdr.company_id', '=', 'companies.id')
            ->leftJoin('company_hubspot_leads as work', 'work.company_id', '=', 'companies.id')
            ->where(static function (Builder $query): void {
                $query->where('sdr.is_eligible', true)
                    ->orWhereNotNull('work.hubspot_deal_id');
            });
    }
}
