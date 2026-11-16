<?php

namespace App\Services;

use App\Models\CompanyHubSpotLead;
use App\Models\HubSpotDeal;
use App\Models\HubSpotPipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Read-only snapshot of owned HubSpot DEALS, not companies.
 * Prefer owner ID stored by HubSpot. Name is only a fallback for imported rows
 * that have no owner ID. Local assignment is a last resort for ownerless deals.
 */
final class DashboardOwnedDealsService
{
    /**
     * @return array{total:int, stages:list<array{label:string,value:int,tone:string}>}
     */
    public function forUser(int $userId): array
    {
        $catalog = $this->stageCatalog();
        $matches = $this->dealsForUser($userId, $catalog['map']);
        $counts = [];
        foreach ($matches as $deal) {
            $label = trim((string) $deal['stage']);
            // EC_PROSPECTOR_V41: etapa descontinuada fica somente no historico do HubSpot.
            if (in_array(mb_strtolower($label), ['início do teste', 'inicio do teste', 'início de teste', 'inicio de teste'], true)) {
                continue;
            }
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        arsort($counts, SORT_NUMERIC);
        $tones = ['teal', 'blue', 'violet', 'steel'];
        $stages = [];
        foreach ($counts as $name => $count) {
            $stages[] = [
                'label' => $name,
                'value' => $count,
                'tone' => $tones[count($stages) % count($tones)],
            ];
        }

        // EC_PROSPECTOR_V40: catálogo completo, incluindo etapas com zero negócios.
        // Mantém primeiro os estágios com negócios, preservando os consumidores V23.
        $known = [];
        foreach ($stages as $stage) {
            $known[mb_strtolower(trim($stage['label']))] = true;
        }
        $stageLabels = array_merge($catalog['labels'], [
            'Reunião Agendada', 'Aceite da proposta', 'Contrato Assinado',
        ]);
        foreach ($stageLabels as $label) {
            $label = trim((string) $label);
            if ($label === '' || in_array(mb_strtolower($label), ['início do teste', 'inicio do teste', 'início de teste', 'inicio de teste'], true) || isset($known[mb_strtolower($label)])) {
                continue;
            }
            $known[mb_strtolower($label)] = true;
            $stages[] = [
                'label' => $label,
                'value' => 0,
                'tone' => 'steel',
            ];
        }

        return ['total' => array_sum($counts), 'stages' => $stages];
    }

    /** @return list<int> */
    public function companyIdsForStage(int $userId, string $stage): array
    {
        $ids = [];
        foreach ($this->dealsForUser($userId) as $deal) {
            if ($deal['stage'] === $stage || $stage === '__all__') {
                foreach ($deal['company_ids'] as $id) {
                    $ids[$id] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /** @return list<int> */
    public function crmOnlyIdsForStage(int $userId, string $stage): array
    {
        $ids = [];
        foreach ($this->dealsForUser($userId) as $deal) {
            if ($deal['stage'] === $stage || $stage === '__all__') {
                foreach ($deal['crm_only_ids'] as $id) {
                    $ids[$id] = true;
                }
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * @param  array<string, string>|null  $catalog
     * @return array<string,array{stage:string,company_ids:list<int>,crm_only_ids:list<int>}>
     */
    private function dealsForUser(int $userId, ?array $catalog = null): array
    {
        if ($userId <= 0) {
            return [];
        }
        $user = User::query()->find($userId);
        if ($user === null) {
            return [];
        }

        $ownerId = trim((string) $user->hubspot_owner_id);
        $ownerName = mb_strtolower(trim((string) $user->name));
        $localIds = DB::table('company_lead_work_states')
            ->where('assigned_user_id', $userId)
            ->pluck('company_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $localLookup = array_fill_keys($localIds, true);

        $catalog ??= $this->stageCatalog()['map'];

        /** @var array<string,array{stage:string,company_ids:list<int>,crm_only_ids:list<int>}> $result */
        $result = [];
        // DASHBOARD_SELECTIVE_DEAL_LOAD_V17_3
        // A identidade do vendedor já está no Deal. Primeiro selecionamos os
        // candidatos pela mesma precedência de ownership anterior; somente
        // depois carregamos as Companies associadas, em uma consulta em lote.
        // Deals sem dono remoto permanecem candidatos para o fallback local.
        // DASHBOARD_OWNER_JSON_PROJECTION_V248
        // A tela nao precisa carregar o JSON completo nem as demais colunas
        // de cada negocio. O seletor JSON e compilado pelo Laravel para
        // PostgreSQL e SQLite; a regra de owner continua sendo verificada em PHP.
        $mirrors = HubSpotDeal::query()->get([
            'id',
            'hubspot_id',
            'owner_name',
            'stage_label',
            'pipeline_id',
            'stage_id',
            'raw_properties->hubspot_owner_id as remote_owner_id',
        ]);
        $knownMirrorIds = [];
        /** @var array<int, bool|null> $ownerDecision */
        $ownerDecision = [];
        $possibleDeals = $mirrors->filter(
            static function (HubSpotDeal $deal) use ($ownerId, $ownerName, &$knownMirrorIds, &$ownerDecision): bool {
                $dealId = trim((string) $deal->hubspot_id);
                if ($dealId === '') {
                    return false;
                }

                // Mesmo um Deal de outro vendedor bloqueia o fallback de
                // snapshot para o seu ID: o mirror tem prioridade.
                $knownMirrorIds[$dealId] = true;

                // Mesmo comportamento anterior: usa somente a propriedade
                // hubspot_owner_id e preserva o fallback por nome ou carteira.
                $remoteOwnerId = trim((string) $deal->getAttribute('remote_owner_id'));
                $remoteOwnerName = mb_strtolower(trim((string) $deal->owner_name));

                if ($remoteOwnerId !== '' && $ownerId !== '') {
                    $decision = hash_equals($ownerId, $remoteOwnerId);
                } elseif ($remoteOwnerName !== '') {
                    $decision = $remoteOwnerName === $ownerName;
                } elseif ($remoteOwnerId !== '') {
                    // ID remoto presente sem mapeamento local: não adivinhar.
                    $decision = false;
                } else {
                    $decision = null; // Confirmar pela carteira local.
                }

                if ($decision === false) {
                    return false;
                }

                $ownerDecision[(int) $deal->id] = $decision;

                return true;
            }
        );
        $possibleDeals->load('commercialCompanies');

        foreach ($possibleDeals as $deal) {
            $dealId = trim((string) $deal->hubspot_id);
            $localOwner = false;
            $companyIds = [];
            $crmOnlyIds = [];
            foreach ($deal->commercialCompanies as $linked) {
                if ($linked->hasTrustedFiscalLink()) {
                    $companyId = (int) $linked->company_id;
                    $companyIds[$companyId] = true;
                    $localOwner = $localOwner || isset($localLookup[$companyId]);
                } elseif ($linked->company_id === null) {
                    $crmOnlyIds[(int) $linked->id] = true;
                }
            }

            // true: owner remoto confirmou; null: usar atribuição local.
            $belongsToMe = $ownerDecision[(int) $deal->id] ?? $localOwner;
            if (! $belongsToMe) {
                continue;
            }

            // EC_PROSPECTOR_V40: stage_id do catálogo tem precedência sobre label obsoleto.
            $label = $catalog[trim((string) $deal->pipeline_id).'|'.trim((string) $deal->stage_id)]
                ?? trim((string) $deal->stage_label);
            if ($label === '') {
                $label = 'Etapa não informada';
            }
            $result[$dealId] = [
                'stage' => $label,
                'company_ids' => array_map('intval', array_keys($companyIds)),
                'crm_only_ids' => array_map('intval', array_keys($crmOnlyIds)),
            ];
        }

        // Fallback for operational deals absent from mirror (ID deduplicates).
        $snapshots = CompanyHubSpotLead::query()
            ->whereIn('company_id', $localIds)
            ->whereNotNull('hubspot_deal_id')
            ->get(['company_id', 'hubspot_deal_id', 'pipeline_id', 'deal_stage_id']);
        foreach ($snapshots as $lead) {
            $id = trim((string) $lead->hubspot_deal_id);
            if ($id === '' || isset($result[$id])) {
                continue;
            }
            // Do not use snapshot if the mirror knows it belongs to somebody else.
            if (isset($knownMirrorIds[$id])) {
                continue;
            }
            $result[$id] = [
                'stage' => $catalog[trim((string) $lead->pipeline_id).'|'.trim((string) $lead->deal_stage_id)]
                    ?? 'Etapa não informada',
                'company_ids' => [(int) $lead->company_id],
                'crm_only_ids' => [],
            ];
        }

        return $result;
    }

    /**
     * @return array{map:array<string,string>,labels:list<string>}
     */
    private function stageCatalog(): array
    {
        $map = [];
        $labels = [];

        $stages = HubSpotPipelineStage::query()
            ->orderBy('display_order')
            ->get(['pipeline_id', 'stage_id', 'stage_label']);

        foreach ($stages as $stage) {
            $label = trim((string) $stage->stage_label);
            $map[trim((string) $stage->pipeline_id).'|'.trim((string) $stage->stage_id)] = $label;

            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return [
            'map' => $map,
            'labels' => $labels,
        ];
    }
}
