<?php

namespace App\Services\Campaigns;

use App\Models\EmailCampaignReply;
use App\Models\HubSpotCompany;
use Illuminate\Support\Facades\DB;

/** Somente leitura: nunca deduz CNPJ por nome ou domínio. */
final class CampaignReplyCompanyV90
{
    /**
     * @param  iterable<EmailCampaignReply>  $replies
     * @return array<int, array{label:string,origin:string,dossier_url:?string,hubspot_url:?string}>
     */
    public function forReplies(iterable $replies): array
    {
        $entries = [];
        $hubspotIds = [];
        $manualIds = [];
        $receitaUuids = [];
        foreach ($replies as $reply) {
            $recipient = $reply->recipient;
            if ($recipient === null) {
                continue;
            }
            $source = (string) ($recipient->source_key ?? '');
            $entries[(int) $reply->id] = [
                'source' => $source,
                'fallback' => trim((string) ($recipient->company_name ?? '')),
            ];
            if (preg_match('/^hubspot:([0-9]+)$/D', $source, $m) === 1) {
                $hubspotIds[$m[1]] = $m[1];
            } elseif (preg_match('/^manual:([0-9]+)$/D', $source, $m) === 1) {
                $manualIds[(int) $m[1]] = (int) $m[1];
            } elseif (preg_match('/^receita:([a-f0-9-]{36})$/iD', $source, $m) === 1) {
                $receitaUuids[$m[1]] = $m[1];
            }
        }

        $mapped = [];
        $contacts = [];
        if ($hubspotIds !== []) {
            $rows = DB::table('hubspot_contacts as h')
                ->join('hubspot_company_contact as link', 'link.hubspot_contact_id', '=', 'h.id')
                ->join('hubspot_companies as co', 'co.id', '=', 'link.hubspot_company_id')
                ->leftJoin('companies as lc', 'lc.id', '=', 'co.company_id')
                ->whereIn('h.hubspot_id', array_values($hubspotIds))
                ->get(['h.hubspot_id as contact_external_id', 'co.id', 'co.hubspot_id', 'co.name', 'co.company_id', 'co.match_source', 'lc.uuid as local_uuid', 'link.is_primary']);
            foreach ($rows as $row) {
                $contacts[(string) $row->contact_external_id][(int) $row->id] = $row;
            }
            // Apenas se não houver associação direta, considerar empresa de negócio vinculado ao contato.
            $deals = DB::table('hubspot_contacts as h')
                ->join('hubspot_deal_contact as dc', 'dc.hubspot_contact_id', '=', 'h.id')
                ->join('hubspot_company_deal as cd', 'cd.hubspot_deal_id', '=', 'dc.hubspot_deal_id')
                ->join('hubspot_companies as co', 'co.id', '=', 'cd.hubspot_company_id')
                ->leftJoin('companies as lc', 'lc.id', '=', 'co.company_id')
                ->whereIn('h.hubspot_id', array_values($hubspotIds))
                ->get(['h.hubspot_id as contact_external_id', 'co.id', 'co.hubspot_id', 'co.name', 'co.company_id', 'co.match_source', 'lc.uuid as local_uuid', 'cd.is_primary']);
            $byDeal = [];
            foreach ($deals as $row) {
                $key = (string) $row->contact_external_id;
                if (! isset($byDeal[$key][(int) $row->id])) {
                    $byDeal[$key][(int) $row->id] = $row;
                } elseif ((bool) $row->is_primary) {
                    $byDeal[$key][(int) $row->id]->is_primary = true;
                }
            }
            foreach ($hubspotIds as $id) {
                $candidates = $contacts[$id] ?? $byDeal[$id] ?? [];
                if ($candidates === []) {
                    continue;
                }
                $primaries = array_filter($candidates, static fn (\stdClass $c): bool => (bool) data_get($c, 'is_primary'));
                if (count($primaries) === 1) {
                    $chosen = reset($primaries);
                } elseif (count($candidates) === 1) {
                    $chosen = reset($candidates);
                } else {
                    $mapped['hubspot:'.$id] = $this->emptyResult('Várias empresas vinculadas — conferir CRM', 'Associação ambígua');

                    continue;
                }
                $mapped['hubspot:'.$id] = $this->fromHubSpot($chosen);
            }
        }

        if ($manualIds !== []) {
            $rows = DB::table('commercial_contacts as m')
                ->join('companies as c', 'c.id', '=', 'm.company_id')
                ->whereIn('m.id', array_values($manualIds))
                ->get(['m.id as contact_id', 'c.uuid', 'c.corporate_name']);
            foreach ($rows as $row) {
                $mapped['manual:'.$row->contact_id] = [
                    'label' => (string) $row->corporate_name,
                    'origin' => 'Vínculo direto · Prospector',
                    'dossier_url' => route('companies.show', ['company' => $row->uuid]),
                    'hubspot_url' => null,
                ];
            }
        }
        if ($receitaUuids !== []) {
            $rows = DB::table('establishments as e')
                ->join('companies as c', 'c.id', '=', 'e.company_id')
                ->whereIn('e.uuid', array_values($receitaUuids))
                ->get(['e.uuid', 'c.uuid as company_uuid', 'c.corporate_name']);
            foreach ($rows as $row) {
                $mapped['receita:'.$row->uuid] = [
                    'label' => (string) $row->corporate_name,
                    'origin' => 'CNPJ · Receita Federal',
                    'dossier_url' => route('companies.show', ['company' => $row->company_uuid]),
                    'hubspot_url' => null,
                ];
            }
        }

        $out = [];
        foreach ($entries as $replyId => $entry) {
            $out[$replyId] = $mapped[$entry['source']] ?? $this->emptyResult(
                $entry['fallback'] !== '' ? $entry['fallback'] : 'Empresa não identificada',
                $entry['fallback'] !== '' ? 'Nome registrado na campanha (não verificado)' : 'Sem vínculo empresarial confirmado',
            );
        }

        return $out;
    }

    /** @return array{label:string,origin:string,dossier_url:?string,hubspot_url:?string} */
    private function fromHubSpot(\stdClass $company): array
    {
        $dossierUrl = null;
        $uuid = data_get($company, 'local_uuid');
        if ($company->company_id !== null && ! HubSpotCompany::isUnsafeFiscalMatchSource($company->match_source)
            && is_string($uuid) && $uuid !== '') {
            $dossierUrl = route('companies.show', ['company' => $uuid]);
        }
        $portal = trim((string) config('services.hubspot.portal_id', ''));
        $hubId = (string) $company->hubspot_id;
        $hubspotUrl = ctype_digit($portal) && ctype_digit($hubId)
            ? 'https://app.hubspot.com/contacts/'.$portal.'/record/0-2/'.$hubId : null;

        return [
            'label' => trim((string) $company->name) ?: 'Empresa HubSpot #'.$hubId,
            'origin' => 'Empresa associada no HubSpot',
            'dossier_url' => $dossierUrl,
            'hubspot_url' => $hubspotUrl,
        ];
    }

    /** @return array{label:string,origin:string,dossier_url:?string,hubspot_url:?string} */
    private function emptyResult(string $label, string $origin): array
    {
        return ['label' => $label, 'origin' => $origin, 'dossier_url' => null, 'hubspot_url' => null];
    }
}
