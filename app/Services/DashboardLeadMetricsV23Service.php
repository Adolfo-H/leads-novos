<?php

namespace App\Services;

use App\Models\Company;
use App\Models\HubSpotCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Dashboard V23: shared, read-only drilldown counts for leads and risks. */
final class DashboardLeadMetricsV23Service
{
    /** @return array{leads:int,unassigned:int,with_deal:int,overdue:int,stale90:int,largest:list<array{id:int,name:string,establishments:int,emails:int,phones:int}>} */
    public function snapshot(): array
    {
        $ids = $this->leadCompanyIds();
        $assigned = DB::table('company_lead_work_states')->whereIn('company_id', $ids)
            ->whereNotNull('assigned_user_id')->pluck('company_id')->map(static fn (mixed $v): int => (int) $v)->all();
        $assignedLookup = array_fill_keys($assigned, true);
        $unassigned = count(array_filter($ids, static fn (int $id): bool => ! isset($assignedLookup[$id])));

        // DASHBOARD_SNAPSHOT_REUSE_IDS_V172: uma selecao de empresas elegiveis por snapshot.
        return [
            'leads' => count($ids),
            'unassigned' => $unassigned,
            'with_deal' => count($this->withDealCompanyIds($ids)),
            'overdue' => count($this->overdueCompanyIds($ids)),
            'stale90' => count($this->staleCompanyIds($ids)),
            'largest' => $this->largestCompanies($ids),
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
            // HUBSPOT_PRIMARY_DEAL_OWNER_V15: um Deal com varias Companies
            // pertence comercialmente apenas a Primary unica e confirmada.
            // Com uma Company, a associacao sozinha e suficiente.
            ->where(static function (Builder $owner): void {
                $owner->whereRaw(
                    '(SELECT COUNT(*) FROM hubspot_company_deal AS all_links '
                    .'WHERE all_links.hubspot_deal_id = hcd.hubspot_deal_id) = 1'
                )->orWhere(static function (Builder $primary): void {
                    $primary->where('hcd.is_primary', true)
                        ->whereRaw(
                            '(SELECT COUNT(*) FROM hubspot_company_deal AS primary_links '
                            .'WHERE primary_links.hubspot_deal_id = hcd.hubspot_deal_id '
                            .'AND primary_links.is_primary = true) = 1'
                        );
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
            // HUBSPOT_PRIMARY_DEAL_OWNER_V15: um Deal com varias Companies
            // pertence comercialmente apenas a Primary unica e confirmada.
            // Com uma Company, a associacao sozinha e suficiente.
            ->where(static function (Builder $owner): void {
                $owner->whereRaw(
                    '(SELECT COUNT(*) FROM hubspot_company_deal AS all_links '
                    .'WHERE all_links.hubspot_deal_id = link.hubspot_deal_id) = 1'
                )->orWhere(static function (Builder $primary): void {
                    $primary->where('link.is_primary', true)
                        ->whereRaw(
                            '(SELECT COUNT(*) FROM hubspot_company_deal AS primary_links '
                            .'WHERE primary_links.hubspot_deal_id = link.hubspot_deal_id '
                            .'AND primary_links.is_primary = true) = 1'
                        );
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
