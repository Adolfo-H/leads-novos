<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Traduz as etapas do dashboard em empresas da carteira autenticada.
 * Faz somente leituras do espelho HubSpot e do fallback operacional.
 */
final class DashboardStageDrilldownService
{
    /** @return list<int> */
    public function companyIdsForStage(int $userId, string $selectedStage): array
    {
        $selectedStage = trim(mb_substr($selectedStage, 0, 150));

        if ($userId <= 0 || $selectedStage === '') {
            return [];
        }

        $summary = app(DashboardMyDealsService::class)->forUser($userId);
        $visibleStages = [];
        $hasOtherStages = false;

        foreach ($summary['stages'] as $stage) {
            if ($stage['label'] === 'Outras etapas') {
                $hasOtherStages = true;
            } else {
                $visibleStages[] = $stage['label'];
            }
        }

        if ($selectedStage === '__others__' && ! $hasOtherStages) {
            return [];
        }

        if ($selectedStage !== '__others__' && ! in_array($selectedStage, $visibleStages, true)) {
            return [];
        }

        $known = [];
        $unique = [];

        foreach (DB::table('hubspot_pipeline_stages')
            ->whereNotNull('stage_id')
            ->get(['pipeline_id', 'stage_id', 'stage_label']) as $stage) {
            $id = trim((string) $stage->stage_id);
            $label = trim((string) $stage->stage_label);
            if ($id === '' || $label === '') {
                continue;
            }

            $known[trim((string) $stage->pipeline_id).'|'.$id] = $label;
            if (! array_key_exists($id, $unique)) {
                $unique[$id] = $label;
            } elseif ($unique[$id] !== $label) {
                $unique[$id] = null;
            }
        }

        /** @var array<string, true> $seenInMirror */
        $seenInMirror = [];
        /** @var array<int, true> $companyIds */
        $companyIds = [];

        // Mesma regra do dashboard V6: Company principal ou vínculo único,
        // sem propagação insegura de CNPJ entre empresas do HubSpot.
        $mirror = DB::table('hubspot_deals as deal')
            ->join('hubspot_company_deal as link', 'link.hubspot_deal_id', '=', 'deal.id')
            ->join('hubspot_companies as company', 'company.id', '=', 'link.hubspot_company_id')
            ->join('company_lead_work_states as owner', 'owner.company_id', '=', 'company.company_id')
            ->leftJoin('hubspot_pipeline_stages as stage', 'stage.id', '=', 'deal.hubspot_pipeline_stage_id')
            ->where('owner.assigned_user_id', $userId)
            ->where(function (Builder $query): void {
                $query->where('link.is_primary', true)
                    ->orWhereRaw('(SELECT COUNT(*) FROM hubspot_company_deal AS associated WHERE associated.hubspot_deal_id = link.hubspot_deal_id) = 1');
            })
            ->whereRaw("LOWER(COALESCE(company.match_source, '')) NOT IN (?, ?)", [
                'hubspot_related_explicit_cnpj',
                'existing_company_unique_domain',
            ])
            ->whereRaw("LOWER(COALESCE(company.match_source, '')) NOT LIKE ?", ['hubspot_related_%'])
            ->whereRaw("LOWER(COALESCE(company.match_source, '')) NOT LIKE ?", ['%inherited_cnpj%'])
            ->whereRaw("LOWER(COALESCE(company.match_source, '')) NOT LIKE ?", ['%propagated_cnpj%'])
            ->get([
                'deal.hubspot_id as deal_id',
                'company.company_id as company_id',
                'deal.pipeline_id',
                'deal.stage_id',
                'deal.stage_label',
                'stage.stage_label as catalog_label',
            ]);

        foreach ($mirror as $deal) {
            $id = trim((string) $deal->deal_id);
            if ($id === '') {
                continue;
            }

            $seenInMirror[$id] = true;
            $label = $this->resolveLabel(
                $deal->stage_label,
                $deal->catalog_label,
                $deal->pipeline_id,
                $deal->stage_id,
                $known,
                $unique,
            );

            if ($this->matches($label, $selectedStage, $visibleStages)) {
                $companyIds[(int) $deal->company_id] = true;
            }
        }

        // Fallback V6: só para deals ausentes do espelho validado.
        $snapshots = DB::table('company_hubspot_leads as lead')
            ->join('company_lead_work_states as owner', 'owner.company_id', '=', 'lead.company_id')
            ->leftJoin('hubspot_deals as deal', 'deal.hubspot_id', '=', 'lead.hubspot_deal_id')
            ->where('owner.assigned_user_id', $userId)
            ->whereNotNull('lead.hubspot_deal_id')
            ->get([
                'lead.company_id',
                'lead.hubspot_deal_id as deal_id',
                'lead.pipeline_id',
                'lead.deal_stage_id as stage_id',
                'deal.stage_label',
            ]);

        foreach ($snapshots as $snapshot) {
            $id = trim((string) $snapshot->deal_id);
            if ($id === '' || isset($seenInMirror[$id])) {
                continue;
            }

            $label = $this->resolveLabel(
                $snapshot->stage_label,
                null,
                $snapshot->pipeline_id,
                $snapshot->stage_id,
                $known,
                $unique,
            );

            if ($this->matches($label, $selectedStage, $visibleStages)) {
                $companyIds[(int) $snapshot->company_id] = true;
            }
        }

        return array_values(array_filter(array_map('intval', array_keys($companyIds)), static fn (int $id): bool => $id > 0));
    }

    /** @param list<string> $visibleStages */
    private function matches(string $label, string $selected, array $visibleStages): bool
    {
        return $selected === '__others__'
            ? ! in_array($label, $visibleStages, true)
            : $label === $selected;
    }

    /**
     * @param  array<string, string>  $known
     * @param  array<string, string|null>  $unique
     */
    private function resolveLabel(
        mixed $direct,
        mixed $catalog,
        mixed $pipeline,
        mixed $stageId,
        array $known,
        array $unique,
    ): string {
        foreach ([$direct, $catalog] as $candidate) {
            $label = trim((string) $candidate);
            if ($label !== '') {
                return $label;
            }
        }

        $stageId = trim((string) $stageId);
        $label = $known[trim((string) $pipeline).'|'.$stageId]
            ?? $unique[$stageId]
            ?? null;

        return $label !== null && $label !== ''
            ? $label
            : 'Etapa não informada';
    }
}
