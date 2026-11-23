<?php

namespace App\Services\Proposals;

use App\Models\EcProposal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

final class HistoricalProposalReadModel
{
    /** @return array<string,mixed> */
    public function listing(Request $request): array
    {
        $base = EcProposal::query()->leftJoin('ec_proposal_history_metadata as hist', 'hist.proposal_id', '=', 'ec_proposals.id');
        // Formal workflow remains unchanged. Historic status is a separate projection.
        $accepted = "(ec_proposals.status = 'accepted' OR (ec_proposals.origin = 'imported' AND hist.commercial_status = 'accepted_crm'))";
        $draft = "(ec_proposals.origin <> 'imported' AND ec_proposals.status = 'draft')";
        $sent = "((ec_proposals.origin = 'imported' AND ec_proposals.status <> 'accepted' AND coalesce(hist.commercial_status, 'sent_history') <> 'accepted_crm') OR (ec_proposals.origin <> 'imported' AND ec_proposals.status IN ('issued','sent')))";
        $counts = [
            'total' => (clone $base)->count('ec_proposals.id'),
            'draft' => (clone $base)->whereRaw($draft)->count('ec_proposals.id'),
            'sent' => (clone $base)->whereRaw($sent)->count('ec_proposals.id'),
            'accepted' => (clone $base)->whereRaw($accepted)->count('ec_proposals.id'),
        ];
        $q = mb_substr($request->string('hist_q')->trim()->toString(), 0, 200);
        $service = $request->string('hist_service')->toString();
        $status = $request->string('hist_status')->toString();
        $year = $request->string('hist_year')->toString();
        if ($q !== '') {
            $pattern = '%'.mb_strtolower($q).'%';
            $base->where(function ($query) use ($pattern): void {
                $query->whereRaw('lower(ec_proposals.client_name) like ?', [$pattern])
                    ->orWhereRaw('lower(hist.title) like ?', [$pattern])
                    ->orWhereRaw('lower(hist.historic_number) like ?', [$pattern])
                    ->orWhereRaw('lower(ec_proposals.legacy_code) like ?', [$pattern]);
            });
        }
        if (in_array($service, ['LICENCIAMENTO', 'RETROATIVAS', 'ACOMPANHAMENTO', 'DFE', 'NFSE'], true)) {
            $base->where(function ($query) use ($service): void {
                $query->whereJsonContains('hist.services', $service)
                    ->orWhere(function ($q) use ($service): void {
                        $q->whereNull('hist.proposal_id')->whereJsonContains('ec_proposals.service_codes', $service);
                    });
            });
        }
        if (isset(['accepted' => $accepted, 'draft' => $draft, 'sent' => $sent][$status])) {
            $base->whereRaw(['accepted' => $accepted, 'draft' => $draft, 'sent' => $sent][$status]);
        }
        if (preg_match('/^20\d{2}$/D', $year) === 1) {
            $base->where(function ($query) use ($year): void {
                $query->where('hist.historic_year', (int) $year)
                    ->orWhereYear('hist.issued_on', $year)
                    ->orWhereYear('ec_proposals.issued_on', $year);
            });
        }
        $page = $base->select('ec_proposals.*')->addSelect([
            'hist.title as hist_title', 'hist.document_name as hist_document_name', 'hist.historic_year as hist_year',
            'hist.historic_number as hist_number', 'hist.issued_on as hist_issued_on', 'hist.responsible_name as hist_responsible_name',
            'hist.responsible_email as hist_responsible_email', 'hist.monthly_amount as hist_monthly_amount', 'hist.one_time_amount as hist_one_time_amount',
            'hist.services as hist_services', 'hist.commercial_status as hist_commercial_status', 'hist.crm_stage as hist_crm_stage',
        ])->orderByDesc('ec_proposals.id')->paginate(30, ['*'], 'hist_page')->withQueryString();
        // Fallback for files with integrity/read errors; use original filename, not the technical hash.
        $ids = $page->getCollection()->pluck('id')->all();
        $names = DB::table('ec_proposal_documents')->whereIn('proposal_id', $ids)->whereIn('kind', ['historical_pdf', 'historical_original'])->orderBy('id')->get(['proposal_id', 'original_name'])->groupBy('proposal_id');

        return ['counts' => $counts, 'page' => $page, 'names' => $names, 'q' => $q, 'service' => $service, 'status' => $status, 'year' => $year];
    }

    public function details(int $id): ?stdClass
    {
        return DB::table('ec_proposal_history_metadata as hist')
            ->leftJoin('hubspot_deals as deal', 'deal.id', '=', 'hist.crm_deal_id')
            ->leftJoin('hubspot_companies as company', 'company.id', '=', 'hist.crm_company_id')
            ->where('hist.proposal_id', $id)->select('hist.*', 'deal.name as deal_name', 'deal.stage_label as current_stage', 'company.name as company_name')->first();
    }

    /** @return list<string> */
    public static function labels(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (! is_array($value)) {
            return [];
        }
        $labels = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $labels[] = $item;
            }
        }

        return $labels;
    }

    public static function money(mixed $value): string
    {
        return is_numeric($value) ? 'R$ '.number_format((float) $value, 2, ',', '.') : 'Não identificado';
    }

    public static function date(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('d/m/Y');
        }
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1) {
            return $m[3].'/'.$m[2].'/'.$m[1];
        }

        return 'Não identificada';
    }
}
