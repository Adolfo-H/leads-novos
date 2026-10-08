<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Etapas reais do HubSpot associadas às empresas da carteira do usuário.
 *
 * O vínculo do usuário é SEMPRE o responsável do Prospector
 * (company_lead_work_states.assigned_user_id). Não usa nome de vendedor
 * como chave nem herda negócios de Companies sem vínculo fiscal confiável.
 *
 * Sem chamadas externas, modificações ou estimativas.
 */
final class DashboardMyDealsService
{
    /**
     * @return array{
     *     total: int,
     *     stages: list<array{label: string, value: int, tone: string}>
     * }
     */
    public function forUser(int $userId): array
    {
        if ($userId <= 0) {
            return ['total' => 0, 'stages' => []];
        }

        // Catálogo atualizado nas sincronizações. Preferimos rótulos, não IDs.
        $labels = [];
        $uniqueLabels = [];

        foreach (DB::table('hubspot_pipeline_stages')
            ->whereNotNull('stage_id')
            ->get(['pipeline_id', 'stage_id', 'stage_label']) as $stage) {
            $id = trim((string) $stage->stage_id);
            $name = trim((string) $stage->stage_label);

            if ($id === '' || $name === '') {
                continue;
            }

            $labels[$this->stageKey($stage->pipeline_id, $id)] = $name;

            if (! array_key_exists($id, $uniqueLabels)) {
                $uniqueLabels[$id] = $name;
            } elseif ($uniqueLabels[$id] !== $name) {
                // Sem pipeline, um mesmo ID ambíguo não deve ser adivinhado.
                $uniqueLabels[$id] = null;
            }
        }

        /** @var array<string, string> $deals */
        $deals = [];

        // Fonte preferencial: espelho dos NEGÓCIOS (pode haver vários por Company).
        // Exige primary Company ou Deal associado a uma só Company.
        $mirror = DB::table('hubspot_deals as deal')
            ->join('hubspot_company_deal as link', 'link.hubspot_deal_id', '=', 'deal.id')
            ->join('hubspot_companies as company', 'company.id', '=', 'link.hubspot_company_id')
            ->join('company_lead_work_states as owner', 'owner.company_id', '=', 'company.company_id')
            ->leftJoin('hubspot_pipeline_stages as stage', 'stage.id', '=', 'deal.hubspot_pipeline_stage_id')
            ->where('owner.assigned_user_id', $userId)
            ->where(function (Builder $query): void {
                $query->where('link.is_primary', true)
                    ->orWhereRaw('(
                        SELECT COUNT(*)
                        FROM hubspot_company_deal AS associated
                        WHERE associated.hubspot_deal_id = link.hubspot_deal_id
                    ) = 1');
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

            $deals[$id] = $this->resolveLabel(
                $deal->stage_label,
                $deal->catalog_label,
                $deal->pipeline_id,
                $deal->stage_id,
                $labels,
                $uniqueLabels,
            );
        }

        // Fallback: snapshot operacional para Deals que ainda não constam
        // no espelho (sem sobrescrever o vínculo primário validado acima).
        $snapshots = DB::table('company_hubspot_leads as lead')
            ->join('company_lead_work_states as owner', 'owner.company_id', '=', 'lead.company_id')
            ->leftJoin('hubspot_deals as deal', 'deal.hubspot_id', '=', 'lead.hubspot_deal_id')
            ->where('owner.assigned_user_id', $userId)
            ->whereNotNull('lead.hubspot_deal_id')
            ->get([
                'lead.hubspot_deal_id as deal_id',
                'lead.pipeline_id',
                'lead.deal_stage_id as stage_id',
                'deal.stage_label',
            ]);

        foreach ($snapshots as $snapshot) {
            $id = trim((string) $snapshot->deal_id);

            if ($id === '' || isset($deals[$id])) {
                continue;
            }

            $deals[$id] = $this->resolveLabel(
                $snapshot->stage_label,
                null,
                $snapshot->pipeline_id,
                $snapshot->stage_id,
                $labels,
                $uniqueLabels,
            );
        }

        $counts = [];

        foreach ($deals as $label) {
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        arsort($counts, SORT_NUMERIC);
        $list = [];
        $tones = ['teal', 'blue', 'violet', 'steel', 'teal'];
        $index = 0;
        $others = 0;

        foreach ($counts as $label => $count) {
            if ($index >= 4) {
                $others += $count;

                continue;
            }

            $list[] = [
                'label' => $label,
                'value' => $count,
                'tone' => $tones[$index],
            ];
            $index++;
        }

        if ($others > 0) {
            $list[] = [
                'label' => 'Outras etapas',
                'value' => $others,
                'tone' => 'steel',
            ];
        }

        return [
            'total' => count($deals),
            'stages' => $list,
        ];
    }

    private function stageKey(mixed $pipelineId, string $stageId): string
    {
        return trim((string) $pipelineId).'|'.$stageId;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  array<string, string|null>  $uniqueLabels
     */
    private function resolveLabel(
        mixed $directLabel,
        mixed $catalogLabel,
        mixed $pipelineId,
        mixed $stageId,
        array $labels,
        array $uniqueLabels,
    ): string {
        foreach ([$directLabel, $catalogLabel] as $label) {
            $text = trim((string) $label);

            if ($text !== '') {
                return $text;
            }
        }

        $stageId = trim((string) $stageId);
        $label = $labels[$this->stageKey($pipelineId, $stageId)]
            ?? $uniqueLabels[$stageId]
            ?? null;

        return $label !== null && $label !== ''
            ? $label
            : 'Etapa não informada';
    }
}
