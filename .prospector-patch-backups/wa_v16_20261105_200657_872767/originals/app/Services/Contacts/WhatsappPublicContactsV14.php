<?php

namespace App\Services\Contacts;

use App\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Mostra canais institucionais em Contatos e no dossiê, sem inventar pessoas. */
final class WhatsappPublicContactsV14
{
    /** @return Collection<int,object> */
    public function search(string $query, int $limit = 20): Collection
    {
        $query = mb_substr(trim($query), 0, 120);
        $digits = (string) preg_replace('/\D+/', '', $query);
        if ($query !== '' && mb_strlen($query) < 3 && strlen($digits) < 7) {
            return collect();
        }
        $terms = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)).'%';
        $items = DB::table('whatsapp_public_company_channels as c')
            ->join('hubspot_companies as h', 'h.id', '=', 'c.hubspot_company_id')
            ->join('whatsapp_public_site_evidences as e', 'e.id', '=', 'c.evidence_id')
            ->leftJoin('whatsapp_phone_numbers as n', 'n.e164', '=', 'c.e164')
            ->when($query !== '', static function ($queryBuilder) use ($digits, $terms): void {
                $queryBuilder->where(static function ($q) use ($digits, $terms): void {
                    if (strlen($digits) >= 7) {
                        $q->where('c.e164', 'like', '%'.$digits.'%');
                    } else {
                        $q->whereRaw("LOWER(COALESCE(h.name, '')) LIKE ? ESCAPE '!'", [$terms])
                            ->orWhereRaw("LOWER(COALESCE(h.domain, '')) LIKE ? ESCAPE '!'", [$terms]);
                    }
                });
            })->orderByDesc('c.last_seen_at')->limit(max(20, min(200, $limit * 5)))
            ->get(['c.id', 'c.e164', 'c.last_seen_at', 'h.name as company_name', 'h.domain',
                'h.hubspot_id', 'e.source_url', 'n.status', 'n.evidence']);

        return $items->unique(fn (object $item): string => $item->hubspot_id.'|'.$item->e164)
            ->take(max(1, min(40, $limit)))->values();
    }

    /** @return Collection<int,array<string,mixed>> */
    public function dossier(Company $company, int $limit = 60): Collection
    {
        $numbers = [];
        $append = static function (?string $raw, string $origin, ?string $sourceUrl = null) use (&$numbers): void {
            $info = BrazilPhoneNumber::inspect($raw);
            if (($info['e164'] ?? null) === null) {
                return;
            }
            $e164 = $info['e164'];
            if (! isset($numbers[$e164])) {
                $numbers[$e164] = [
                    'e164' => $e164, 'display' => BrazilPhoneNumber::display($e164, $e164),
                    'sources' => [], 'published' => false, 'source_url' => null,
                    'status' => 'unknown', 'evidence' => null,
                ];
            }
            $numbers[$e164]['sources'][$origin] = true;
            if ($sourceUrl !== null && filter_var($sourceUrl, FILTER_VALIDATE_URL)
                && in_array(strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME)), ['http','https'], true)) {
                $numbers[$e164]['published'] = true;
                $numbers[$e164]['source_url'] ??= $sourceUrl;
            }
        };

        // Contatos cadastrais da Receita (vínculo local explícito).
        foreach ($company->establishments as $est) {
            $append($est->phone_1, 'Receita · '.($est->type === 'matrix' ? 'Matriz' : 'Filial'));
            $append($est->phone_2, 'Receita · '.($est->type === 'matrix' ? 'Matriz' : 'Filial'));
        }
        // Pessoas vinculadas por relacionamento direto a um HubSpot Company fiscalmente confiável.
        $trustedIds = DB::table('hubspot_companies')->where('company_id', $company->id)
            ->get(['id','match_source'])->filter(static fn ($h): bool =>
                ! \App\Models\HubSpotCompany::isUnsafeFiscalMatchSource($h->match_source))
            ->pluck('id')->all();
        if ($trustedIds !== []) {
            foreach (DB::table('hubspot_company_contact as link')
                ->join('hubspot_contacts as hc', 'hc.id', '=', 'link.hubspot_contact_id')
                ->whereIn('link.hubspot_company_id', $trustedIds)->distinct()
                ->limit(220)->get(['hc.phone','hc.mobile_phone']) as $contact) {
                $append($contact->phone, 'HubSpot');
                $append($contact->mobile_phone, 'HubSpot');
            }
        }
        // Evidências de WhatsApp público guardadas na V13, sem vínculo fiscal inferido.
        foreach (DB::table('whatsapp_public_company_channels as c')
            ->join('whatsapp_public_site_evidences as e', 'e.id', '=', 'c.evidence_id')
            ->join('hubspot_companies as hco', 'hco.id', '=', 'c.hubspot_company_id')
            ->where('c.company_id', $company->id)->limit(300)
            ->get(['c.e164','e.source_url','hco.match_source']) as $channel) {
            if (\App\Models\HubSpotCompany::isUnsafeFiscalMatchSource($channel->match_source)) {
                continue;
            }
            $append($channel->e164, 'Site público da empresa', $channel->source_url);
        }
        if ($numbers === []) {
            return collect();
        }
        $statuses = DB::table('whatsapp_phone_numbers')->whereIn('e164', array_keys($numbers))
            ->get(['e164', 'status', 'evidence'])->keyBy('e164');
        foreach ($numbers as $e164 => &$item) {
            $match = $statuses->get($e164);
            $item['status'] = in_array($match?->status, ['yes', 'no'], true) ? $match->status : 'unknown';
            $item['evidence'] = $match?->evidence;
            $item['sources'] = implode(' · ', array_keys($item['sources']));
        }
        unset($item);
        return collect(array_values($numbers))->sortBy(static fn (array $p): string =>
            ($p['status'] === 'yes' ? '0' : ($p['published'] ? '1' : '2')).'|'.$p['e164'])
            ->take(max(1, min(100, $limit)))->values();
    }
}
