<?php

namespace App\Services\Contacts;

use App\Models\HubSpotCompany;
use App\Support\TextNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * V86: agrupa resultados por nome comercial APENAS na apresentação.
 * Telefones vêm de relacionamentos explícitos, nunca da similaridade de nomes.
 * Não consulta APIs externas, não altera CRM e não altera status WhatsApp.
 */
final class WhatsappBusinessDirectoryV86
{
    /** @var list<string> */
    private const GENERIC_FIRST_WORDS = [
        'COOPERATIVA', 'INDUSTRIA', 'INDUSTRIAS', 'COMERCIO', 'EMPRESA',
        'EMPRESAS', 'AGROPECUARIA', 'AGROINDUSTRIA', 'FAZENDA', 'GRUPO',
        'SOCIEDADE', 'COMPANHIA', 'DISTRIBUIDORA', 'ADMINISTRADORA',
        'TRANSPORTADORA', 'SERVICOS', 'ASSOCIACAO', 'CONSORCIO',
    ];

    /** @return Collection<int, \stdClass> */
    public function search(string $term, int $limit = 36): Collection
    {
        $term = mb_substr(trim($term), 0, 100);
        if (mb_strlen($term) < 3) {
            return collect();
        }

        $max = max(1, min($limit, 60));
        $raw = app(WhatsappBusinessDirectoryV85::class)->search($term, 60);
        if ($raw->isEmpty()) {
            return collect();
        }

        // Se um negócio está ligado a uma única Company ou a uma Primary
        // explícita, agrupá-lo visualmente sob o nome da Company.
        // Nunca propagar CNPJ nem usar isso como identidade fiscal.
        $dealIds = $raw->map(static function (object $r): ?int {
            $key = (string) data_get($r, 'key', '');

            return preg_match('/^deal:([1-9][0-9]*)$/D', $key, $m) === 1 ? (int) $m[1] : null;
        })->filter()->values()->all();
        $dealGroups = [];
        if ($dealIds !== []) {
            $links = DB::table('hubspot_company_deal as l')
                ->join('hubspot_companies as h', 'h.id', '=', 'l.hubspot_company_id')
                ->whereIn('l.hubspot_deal_id', $dealIds)
                ->get(['l.hubspot_deal_id', 'l.is_primary', 'h.name', 'h.id as hubspot_company_id']);
            foreach ($links->groupBy('hubspot_deal_id') as $dealId => $group) {
                $primaries = $group->filter(static fn (object $r): bool => (bool) data_get($r, 'is_primary'));
                $selected = $primaries->count() === 1 ? $primaries->first()
                    : ($group->count() === 1 ? $group->first() : null);
                if ($selected !== null) {
                    $brand = $this->brand((string) data_get($selected, 'name', ''));
                    $dealGroups[(int) $dealId] = $brand !== null
                        ? 'brand:'.$brand
                        : 'hubspot:'.(int) data_get($selected, 'hubspot_company_id');
                }
            }
        }

        /** @var array<string,array{candidates:list<object>,brand:?string}> $groups */
        $groups = [];
        foreach ($raw as $candidate) {
            $key = (string) data_get($candidate, 'key', '');
            $name = (string) data_get($candidate, 'name', '');
            $brand = $this->brand($name);
            if (preg_match('/^deal:([1-9][0-9]*)$/D', $key, $m) === 1) {
                $groupKey = $dealGroups[(int) $m[1]] ?? ($brand !== null ? 'brand:'.$brand : $key);
            } else {
                $groupKey = $brand !== null ? 'brand:'.$brand : $key;
            }
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = ['candidates' => [], 'brand' => $brand];
            }
            $groups[$groupKey]['candidates'][] = $candidate;
        }

        $results = [];
        foreach ($groups as $key => $group) {
            $members = $group['candidates'];
            $companies = array_values(array_filter($members, static fn (object $r): bool => str_starts_with((string) data_get($r, 'key', ''), 'hubspot:')));
            $local = array_values(array_filter($members, static fn (object $r): bool => str_starts_with((string) data_get($r, 'key', ''), 'local:')));
            $deals = array_values(array_filter($members, static fn (object $r): bool => str_starts_with((string) data_get($r, 'key', ''), 'deal:')));
            $preferred = $companies[0] ?? $local[0] ?? $members[0];
            $name = (string) data_get($preferred, 'name', 'Empresa / negócio');
            $domain = data_get($preferred, 'domain');
            $count = count($members);
            $details = $count === 1
                ? (string) data_get($preferred, 'details', '')
                : count($companies).' empresa(s) no HubSpot · '.count($local)
                    .' cadastro(s) Receita · '.count($deals).' negócio(s)';
            $results[] = (object) [
                'key' => $key,
                'name' => $name,
                'source' => $count > 1 ? 'Empresa · registros comerciais agrupados' : (string) data_get($preferred, 'source', ''),
                'details' => $details,
                'domain' => is_string($domain) ? $domain : null,
                'grouped' => $count > 1,
                'member_keys' => array_map(
                    static fn (object $member): string => (string) data_get($member, 'key', ''), $members
                ),
            ];
        }

        /** @var Collection<int, \stdClass> $resultCollection */
        $resultCollection = collect($results);

        return $resultCollection->sortBy(static function (object $item): string {
            $name = (string) data_get($item, 'name', '');

            return mb_strtolower($name).'|'.(string) data_get($item, 'key', '');
        })->take($max)->values();
    }

    /** @return array<string,mixed>|null */
    public function snapshot(string $key): ?array
    {
        $members = [];
        if (preg_match('/^brand:([A-Z0-9]{7,40})$/D', $key, $m) === 1) {
            $match = $this->search($m[1], 60)->first(static fn (object $r): bool => data_get($r, 'key') === $key);
            if ($match === null) {
                return null;
            }
            $members = (array) data_get($match, 'member_keys', []);
        } elseif (preg_match('/^(hubspot|deal|local):([1-9][0-9]{0,17})$/D', $key) === 1) {
            $members = [$key];
        } else {
            return null;
        }

        $hIds = [];
        $localIds = [];
        $dealIds = [];
        foreach ($members as $member) {
            if (! is_string($member)) {
                continue;
            }
            if (preg_match('/^(hubspot|deal|local):([1-9][0-9]{0,17})$/D', $member, $m) !== 1) {
                continue;
            }
            $id = (int) $m[2];
            if ($m[1] === 'hubspot') {
                $hIds[$id] = $id;
            } elseif ($m[1] === 'local') {
                $localIds[$id] = $id;
            } else {
                $dealIds[$id] = $id;
            }
        }
        $hubspots = $hIds === [] ? collect() : DB::table('hubspot_companies')
            ->whereIn('id', array_values($hIds))->get();
        foreach ($hubspots as $h) {
            $localId = data_get($h, 'company_id');
            if ($localId !== null && ! HubSpotCompany::isUnsafeFiscalMatchSource(data_get($h, 'match_source'))) {
                $localIds[(int) $localId] = (int) $localId;
            }
        }
        $locals = $localIds === [] ? collect() : DB::table('companies')
            ->whereIn('id', array_values($localIds))
            ->get(['id', 'corporate_name', 'cnpj_root']);
        $localIds = $locals->map(static fn (object $l): int => (int) data_get($l, 'id'))->all();

        // Negócios explicitamente ligados às Companies da seleção.
        $companyDealLinks = $hubspots->isEmpty() ? collect() : DB::table('hubspot_company_deal as l')
            ->whereIn('l.hubspot_company_id', $hubspots->pluck('id')->all())
            ->get(['l.hubspot_company_id', 'l.hubspot_deal_id', 'l.is_primary']);
        $associatedIds = $companyDealLinks->pluck('hubspot_deal_id')->map(static fn ($i): int => (int) $i)->all();
        $allDealIds = array_values(array_unique(array_merge(array_values($dealIds), $associatedIds)));
        $deals = $allDealIds === [] ? collect() : DB::table('hubspot_deals')
            ->whereIn('id', $allDealIds)->orderByDesc('id')
            ->get(['id', 'name', 'hubspot_id', 'stage_label', 'pipeline_label']);
        $validDealIds = $deals->pluck('id')->map(static fn ($i): int => (int) $i)->all();
        if ($hubspots->isEmpty() && $locals->isEmpty() && $deals->isEmpty()) {
            return null;
        }

        // Somente Deals de ownership não ambíguo fornecem telefones ao registro
        // da Company. Deals explicitamente procurados são exibidos de forma
        // independente, como fontes próprias, nunca como prova fiscal.
        $usableDealIds = array_values($dealIds);
        if ($companyDealLinks->isNotEmpty()) {
            $linksByDeal = DB::table('hubspot_company_deal')
                ->whereIn('hubspot_deal_id', $associatedIds)
                ->get(['hubspot_deal_id', 'hubspot_company_id', 'is_primary'])
                ->groupBy('hubspot_deal_id');
            foreach ($companyDealLinks as $link) {
                $dealId = (int) data_get($link, 'hubspot_deal_id');
                $all = $linksByDeal->get($dealId, collect());
                $primary = $all->filter(static fn (object $item): bool => (bool) data_get($item, 'is_primary'));
                if ($primary->count() === 1 && (int) data_get($primary->first(), 'hubspot_company_id') === (int) data_get($link, 'hubspot_company_id')) {
                    $usableDealIds[] = $dealId;
                } elseif ($all->count() === 1) {
                    $usableDealIds[] = $dealId;
                }
            }
        }
        $usableDealIds = array_values(array_unique(array_intersect($usableDealIds, $validDealIds)));

        /** @var array<string, string> $keys */
        $keys = [];
        $hIdList = $hubspots->pluck('id')->all();
        if ($hIdList !== []) {
            $contacts = DB::table('hubspot_company_contact as hc')
                ->join('hubspot_contacts as c', 'c.id', '=', 'hc.hubspot_contact_id')
                ->whereIn('hc.hubspot_company_id', $hIdList)
                ->get(['c.hubspot_id']);
            foreach ($contacts as $c) {
                $keys['hubspot:'.(string) data_get($c, 'hubspot_id')] = 'Contatos vinculados à empresa HubSpot';
            }
            $manual = DB::table('commercial_contacts')
                ->whereIn('hubspot_company_external_id', $hubspots->pluck('hubspot_id')->all())->pluck('id');
            foreach ($manual as $id) {
                $keys['manual:'.$id] = 'Contato vinculado ao cadastro HubSpot';
            }
        }
        if ($localIds !== []) {
            $manuals = DB::table('commercial_contacts')->whereIn('company_id', $localIds)->pluck('id');
            foreach ($manuals as $id) {
                $keys['manual:'.$id] = 'Contato da empresa fiscal';
            }
            $establishments = DB::table('establishments')->whereIn('company_id', $localIds)->pluck('uuid');
            foreach ($establishments as $uuid) {
                $keys['receita:'.$uuid] = 'Telefone do estabelecimento Receita';
            }
            $crmContacts = DB::table('company_hubspot_leads as l')
                ->join('hubspot_contacts as c', 'c.id', '=', 'l.hubspot_contact_id')
                ->whereIn('l.company_id', $localIds)
                ->whereNotNull('l.hubspot_contact_id')->pluck('c.hubspot_id');
            foreach ($crmContacts as $hubId) {
                $keys['hubspot:'.$hubId] = 'Contato comercial com vínculo fiscal confirmado';
            }
        }
        if ($usableDealIds !== []) {
            $contacts = DB::table('hubspot_deal_contact as dc')
                ->join('hubspot_contacts as c', 'c.id', '=', 'dc.hubspot_contact_id')
                ->whereIn('dc.hubspot_deal_id', $usableDealIds)->pluck('c.hubspot_id');
            foreach ($contacts as $hubId) {
                $keys['hubspot:'.$hubId] = 'Contato diretamente vinculado ao negócio';
            }
        }

        $byId = [];
        $phoneOrigins = [];
        foreach (array_chunk(array_keys($keys), 400) as $chunk) {
            $rows = DB::table('whatsapp_phone_sources as s')
                ->join('whatsapp_phone_numbers as n', 'n.id', '=', 's.phone_id')
                ->whereIn('s.source_key', $chunk)
                ->get(['s.source_key', 'n.id', 'n.e164', 'n.digits', 'n.kind', 'n.status', 'n.contacts_count']);
            foreach ($rows as $row) {
                $phoneId = (int) data_get($row, 'id');
                $byId[$phoneId] = $row;
                $phoneOrigins[$phoneId][$keys[(string) data_get($row, 'source_key')] ?? 'Fonte associada'] = true;
            }
        }
        ksort($byId);
        $phones = collect(array_values($byId))->take(100)->values();
        foreach ($phones as $phone) {
            $phone->source_labels = array_keys($phoneOrigins[(int) data_get($phone, 'id')] ?? []);
        }

        $domainCandidates = $hubspots->map(static fn (object $h): ?string => SafePublicWhatsappWebsiteFetcher::domain((string) data_get($h, 'domain', '')))
            ->filter()->unique()->values();
        $domain = $domainCandidates->count() === 1 ? (string) $domainCandidates->first() : null;
        $siteCompanyId = $domain !== null && $hubspots->count() === 1 ? (int) data_get($hubspots->first(), 'id') : null;
        $displayName = (string) data_get($hubspots->first(), 'name', '')
            ?: (string) data_get($locals->first(), 'corporate_name', '')
            ?: (string) data_get($deals->first(), 'name', 'Empresa / negócio');
        $companyNames = $hubspots->pluck('name')->concat($locals->pluck('corporate_name'))
            ->filter()->unique()->values()->all();
        $recordNames = [];
        foreach ($hubspots as $h) {
            $recordNames[] = ['name' => (string) data_get($h, 'name', ''), 'origin' => 'Empresa HubSpot'];
        }
        foreach ($locals as $l) {
            $recordNames[] = ['name' => (string) data_get($l, 'corporate_name', ''), 'origin' => 'Cadastro Receita/Prospector'];
        }
        foreach ($deals as $d) {
            $recordNames[] = ['name' => (string) data_get($d, 'name', ''), 'origin' => 'Negócio HubSpot'];
        }

        return [
            'key' => $key,
            'name' => $displayName,
            'source' => count($members) > 1 ? 'Resultados agrupados por nome; identidade fiscal não inferida' : 'Vínculos reais do registro selecionado',
            'domain' => $domain,
            'site_hubspot_company_id' => $siteCompanyId,
            'linked_companies' => $companyNames,
            'phones' => $phones,
            'phone_total' => count($byId),
            'confirmed_total' => collect($byId)->where('status', 'yes')->count(),
            'grouped' => count($members) > 1,
            'records' => $recordNames,
            'deal_total' => $deals->count(),
        ];
    }

    private function brand(string $name): ?string
    {
        $normal = TextNormalizer::companyName($name) ?? '';
        $first = explode(' ', $normal)[0];
        if (strlen($first) < 7 || strlen($first) > 40 || ! preg_match('/^[A-Z0-9]+$/D', $first)
            || in_array($first, self::GENERIC_FIRST_WORDS, true)) {
            return null;
        }

        return $first;
    }
}
