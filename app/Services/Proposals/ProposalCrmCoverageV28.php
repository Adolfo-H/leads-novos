<?php

namespace App\Services\Proposals;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ProposalCrmCoverageV28
{
    public function acceptedDeals(): Builder
    {
        $q = DB::table('hubspot_deals as d')->where(function (Builder $q): void {
            $q->whereNull('d.pipeline_id')->orWhere('d.pipeline_id', '')->orWhere('d.pipeline_id', 'default');
        })->where(function (Builder $q): void {
            $q->whereIn('d.stage_id', ProposalCrmMatchV28::STAGES)->orWhere(function (Builder $q): void {
                $q->where(function (Builder $q): void {
                    $q->whereNull('d.stage_id')->orWhere('d.stage_id', '');
                })->whereRaw('LOWER(d.stage_label) IN (?, ?, ?, ?, ?, ?)', ['aceite da proposta', 'contrato assinado', 'inicio do teste', 'início do teste', 'negócio fechado', 'negocio fechado']);
            });
        });
        if (Schema::hasColumn('hubspot_deals', 'archived')) {
            $q->where(function (Builder $q): void {
                $q->whereNull('d.archived')->orWhere('d.archived', false);
            });
        }

        return $q;
    }

    public function uncoveredDeals(): Builder
    {
        return $this->acceptedDeals()->whereNotExists(function (Builder $q): void {
            $q->selectRaw('1')->from('ec_proposals as p')->where(function (Builder $q): void {
                $q->where(function (Builder $q): void {
                    $q->where('p.status', 'accepted')->whereColumn('p.hubspot_deal_id', 'd.id');
                })->orWhere(function (Builder $q): void {
                    $q->where('p.origin', 'imported')->whereExists(function (Builder $q): void {
                        $q->selectRaw('1')->from('ec_proposal_history_metadata as h')->whereColumn('h.proposal_id', 'p.id')->whereColumn('h.crm_deal_id', 'd.id')->where('h.commercial_status', 'accepted_crm');
                    });
                });
            });
        });
    }

    /** @return array{crm:int,covered:int,uncovered:int,mirror_updated_at:mixed} */
    public function summary(): array
    {
        $crm = $this->acceptedDeals()->count();
        $uncovered = $this->uncoveredDeals()->count();

        return ['crm' => $crm, 'covered' => max(0, $crm - $uncovered), 'uncovered' => $uncovered, 'mirror_updated_at' => DB::table('hubspot_deals')->max('updated_at')];
    }

    /** @return array<string, mixed> */
    public function reconcile(bool $apply = false): array
    {
        // EC_ATTACHMENT_V29: intentionally disabled; no name/service inference can grant acceptance.
        return ['mode' => $apply ? 'apply_disabled' : 'preview_disabled', 'count' => 0, 'changes' => [], 'pending' => [], 'coverage' => $this->summary()];
    }
}
