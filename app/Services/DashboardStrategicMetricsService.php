<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Read-only analytics for the strategic dashboard.
 * No HubSpot requests, no mutations and no financial estimates.
 */
final class DashboardStrategicMetricsService
{
    /**
     * @return array{
     *   export: array{
     *     confirmed_companies: int,
     *     direct: int,
     *     indirect: int,
     *     trading: int,
     *     researched: int
     *   },
     *   cnaes: list<array{code: string, description: string, companies: int}>
     * }
     */
    public function snapshot(): array
    {
        // One export-intelligence record per company.
        // Confirmed categories can overlap; do not sum them as unique companies.
        $export = DB::table('company_export_intelligences')
            ->selectRaw('SUM(CASE WHEN direct_confirmed = ? THEN 1 ELSE 0 END) AS direct_total', [true])
            ->selectRaw('SUM(CASE WHEN indirect_confirmed = ? THEN 1 ELSE 0 END) AS indirect_total', [true])
            ->selectRaw('SUM(CASE WHEN trading_confirmed = ? THEN 1 ELSE 0 END) AS trading_total', [true])
            ->selectRaw('SUM(CASE WHEN direct_confirmed = ? OR indirect_confirmed = ? OR trading_confirmed = ? THEN 1 ELSE 0 END) AS confirmed_total', [true, true, true])
            ->selectRaw('SUM(CASE WHEN researched_at IS NOT NULL THEN 1 ELSE 0 END) AS researched_total')
            ->first();

        // Distinct companies, not the number of branches or CNAE associations.
        // Only actual eligible SDR-scored leads are considered.
        $cnaes = DB::table('cnaes as c')
            ->join('cnae_establishment as ce', 'ce.cnae_id', '=', 'c.id')
            ->join('establishments as e', 'e.id', '=', 'ce.establishment_id')
            ->join('company_sdr_scores as sdr', 'sdr.company_id', '=', 'e.company_id')
            ->where('sdr.is_eligible', true)
            ->where('ce.is_primary', true)
            ->select(['c.code', 'c.description'])
            ->selectRaw('COUNT(DISTINCT e.company_id) AS companies_total')
            ->groupBy('c.code', 'c.description')
            ->orderByDesc('companies_total')
            ->orderBy('c.code')
            ->limit(5)
            ->get()
            ->map(static function (object $row): array {
                return [
                    'code' => (string) $row->code,
                    'description' => (string) ($row->description ?? 'Atividade não informada'),
                    'companies' => (int) $row->companies_total,
                ];
            })
            ->values()
            ->all();

        return [
            'export' => [
                'confirmed_companies' => (int) ($export->confirmed_total ?? 0),
                'direct' => (int) ($export->direct_total ?? 0),
                'indirect' => (int) ($export->indirect_total ?? 0),
                'trading' => (int) ($export->trading_total ?? 0),
                'researched' => (int) ($export->researched_total ?? 0),
            ],
            'cnaes' => array_values($cnaes),
        ];
    }
}
