<?php

namespace App\Http\Controllers;

use App\Models\EcProposal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class HistoricalProposalCrmController extends Controller
{
    public function confirm(Request $request, EcProposal $proposal): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isCommercialManager(), 403);
        abort_unless($proposal->origin === 'imported', 422);
        $data = $request->validate([
            'company_external_id' => ['required', 'string', 'regex:/^[0-9]{1,20}$/D'],
            'deal_external_id' => ['required', 'string', 'regex:/^[0-9]{1,20}$/D'],
            'confirm_crm' => ['accepted'],
        ]);
        DB::transaction(function () use ($proposal, $data, $actor): void {
            $p = EcProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->origin === 'imported', 422);
            $meta = DB::table('ec_proposal_history_metadata')->where('proposal_id', $p->id)->lockForUpdate()->first();
            abort_if($meta === null, 422, 'Metadados históricos indisponíveis.');
            $company = DB::table('hubspot_companies')->where('hubspot_id', $data['company_external_id'])->first();
            $deal = DB::table('hubspot_deals')->where('hubspot_id', $data['deal_external_id'])->first();
            if ($company === null || $deal === null) {
                throw ValidationException::withMessages(['deal_external_id' => 'Empresa ou negócio não consta do espelho local do HubSpot.']);
            }
            $links = DB::table('hubspot_company_deal')->where('hubspot_deal_id', $deal->id)->get();
            $own = $links->firstWhere('hubspot_company_id', $company->id);
            if ($own === null || ($links->count() !== 1 && (! $own->is_primary || $links->where('is_primary', true)->count() !== 1))) {
                throw ValidationException::withMessages(['deal_external_id' => 'O negócio não pertence comercialmente à empresa selecionada. Confira a empresa principal no HubSpot.']);
            }
            $evidence = json_decode((string) $meta->evidence, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($evidence)) {
                $evidence = [];
            }
            $previous = ['company_id' => $p->hubspot_company_id, 'deal_id' => $p->hubspot_deal_id, 'metadata_company_id' => $meta->crm_company_id, 'metadata_deal_id' => $meta->crm_deal_id, 'crm_match' => $meta->crm_match];
            $stage = (string) ($deal->stage_label ?? '');
            $accepted = in_array(mb_strtoupper(Str::ascii($stage)), ['ACEITE DA PROPOSTA', 'CONTRATO ASSINADO', 'INICIO DO TESTE', 'NEGOCIO FECHADO'], true);
            $evidence['crm_v4'] = ['version' => 4, 'manual' => true, 'match' => 'manual_confirmed', 'company_reason' => 'manager_review', 'company_candidates' => [(int) $company->id], 'deal_candidates' => [(int) $deal->id], 'generic_candidate' => null];
            DB::table('ec_proposals')->where('id', $p->id)->update([
                'hubspot_company_id' => $company->id,
                'hubspot_deal_id' => $deal->id,
                'updated_by' => $actor->id,
                'updated_at' => now('UTC'),
            ]);
            DB::table('ec_proposal_history_metadata')->where('proposal_id', $p->id)->update([
                'crm_company_id' => $company->id,
                'crm_deal_id' => $deal->id,
                'crm_stage' => $stage !== '' ? $stage : null,
                'crm_match' => 'manual_confirmed',
                'commercial_status' => $accepted ? 'accepted_crm' : 'sent_history',
                'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => now('UTC'),
            ]);
            DB::table('ec_proposal_events')->insert([
                'proposal_id' => $p->id,
                'event' => 'historical_crm_link_confirmed',
                'metadata' => json_encode(['previous' => $previous, 'company_id' => $company->id, 'deal_id' => $deal->id, 'stage' => $stage], JSON_THROW_ON_ERROR),
                'actor_id' => $actor->id,
                'created_at' => now('UTC'),
            ]);
        });

        return redirect()->route('proposals.show', $proposal)->with('history_crm_success', 'Vínculo comercial confirmado no Prospector. Nenhum dado remoto foi alterado.');
    }
}
