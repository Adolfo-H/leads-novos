<?php

namespace App\Services\Contacts;

use App\Models\HubSpotCompany;
use App\Support\TextNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Diretório de seleção comercial: HubSpot Companies, HubSpot Deals e empresas
 * fiscais locais/Receita, inclusive sem site. Nenhum vínculo é inferido por nome.
 * Não consulta sites, não envia mensagens e não grava no CRM.
 */
final class WhatsappBusinessDirectoryV85
{
    /** @return Collection<int, \stdClass> */
    public function search(string $term, int $limit = 36): Collection
    {
        $term = mb_substr(trim($term), 0, 100);
        if (mb_strlen($term) < 3) {
            return collect();
        }

        $limit = max(1, min(60, $limit));
        $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
        $normalized = TextNormalizer::companyName($term) ?? '';
        $digits = preg_replace('/\D+/', '', $term) ?: '';
        $rows = collect();

        // HubSpot Company: incluir registros SEM domínio, inclusive aqueles com negócios.
        $companies = DB::table('hubspot_companies as h')
            ->where(static function ($q) use ($needle): void {
                $q->whereRaw("LOWER(COALESCE(h.name, '')) LIKE ? ESCAPE '!'", [$needle])
                    ->orWhereRaw("LOWER(COALESCE(h.domain, '')) LIKE ? ESCAPE '!'", [$needle])
                    ->orWhereRaw("LOWER(COALESCE(h.hubspot_id, '')) LIKE ? ESCAPE '!'", [$needle]);
            })->orderBy('h.name')->limit($limit)->get(['h.id', 'h.name', 'h.domain', 'h.hubspot_id']);
        foreach ($companies as $company) {
            $rows->push((object) [
                'key' => 'hubspot:'.$company->id,
                'name' => (string) ($company->name ?: 'Empresa HubSpot '.$company->hubspot_id),
                'source' => 'Empresa · HubSpot',
                'domain' => SafePublicWhatsappWebsiteFetcher::domain((string) $company->domain),
                'details' => 'ID HubSpot: '.$company->hubspot_id,
            ]);
        }

        // Negócios são pesquisáveis mesmo sem Company ou site associado.
        $deals = DB::table('hubspot_deals as d')
            ->where(static function ($q) use ($needle): void {
                $q->whereRaw("LOWER(COALESCE(d.name, '')) LIKE ? ESCAPE '!'", [$needle])
                    ->orWhereRaw("LOWER(COALESCE(d.hubspot_id, '')) LIKE ? ESCAPE '!'", [$needle]);
            })->orderByDesc('d.id')->limit($limit)
            ->get(['d.id', 'd.name', 'd.hubspot_id', 'd.stage_label']);
        foreach ($deals as $deal) {
            $rows->push((object) [
                'key' => 'deal:'.$deal->id,
                'name' => (string) ($deal->name ?: 'Negócio HubSpot '.$deal->hubspot_id),
                'source' => 'Negócio · HubSpot',
                'domain' => null,
                'details' => (string) ($deal->stage_label ?: 'Etapa não informada'),
            ]);
        }

        // Receita/Prospector: busca prefixada sobre nome normalizado. Não
        // fazemos ILIKE %texto% em toda a base da Receita a cada tecla.
        $local = DB::table('companies as c')
            ->where(static function ($q) use ($normalized, $digits): void {
                if ($normalized !== '') {
                    $q->where('c.normalized_name', 'like', $normalized.'%');
                }
                if (strlen($digits) >= 8) {
                    $q->orWhere('c.cnpj_root', substr($digits, 0, 8));
                }
                if (strlen($digits) === 14) {
                    $q->orWhereExists(static function ($sub) use ($digits): void {
                        $sub->selectRaw('1')->from('establishments as e')
                            ->whereColumn('e.company_id', 'c.id')->where('e.cnpj', $digits);
                    });
                }
            })->orderBy('c.corporate_name')->limit($limit)
            ->get(['c.id', 'c.corporate_name', 'c.cnpj_root']);

        // Nome completo pode ter abreviações diferentes entre CRM e Receita:
        // neste caso, apresentar sugestões pelo PRIMEIRO termo distintivo,
        // sem atribuir automaticamente identidade a nenhum resultado.
        if ($local->isEmpty() && str_contains((string) $normalized, ' ')) {
            $first = explode(' ', (string) $normalized)[0];
            if (strlen($first) >= 5) {
                $local = DB::table('companies as c')->where('c.normalized_name', 'like', $first.'%')
                    ->orderBy('c.corporate_name')->limit($limit)
                    ->get(['c.id', 'c.corporate_name', 'c.cnpj_root']);
            }
        }
        foreach ($local as $company) {
            $rows->push((object) [
                'key' => 'local:'.$company->id,
                'name' => (string) $company->corporate_name,
                'source' => 'Empresa · Receita/Prospector',
                'domain' => null,
                'details' => 'Raiz CNPJ: '.(string) $company->cnpj_root,
            ]);
        }

        // Priorizar correspondência de nome completo, sem suprimir fontes.
        $normalTerm = mb_strtolower($term);

        return $rows->sortBy(static function (object $item) use ($normalTerm): string {
            $name = mb_strtolower((string) data_get($item, 'name', ''));
            $priority = $name === $normalTerm ? 0 : (str_starts_with($name, $normalTerm) ? 1 : 2);

            return $priority.'|'.$name.'|'.(string) data_get($item, 'key', '');
        })->take($limit)->values();
    }

    /** @return array<string, mixed>|null */
    public function snapshot(string $key): ?array
    {
        if (preg_match('/^(hubspot|deal|local):([1-9][0-9]{0,17})$/D', $key, $matches) !== 1) {
            return null;
        }
        $kind = $matches[1];
        $id = (int) $matches[2];
        $sourceKeys = [];
        $hubspots = collect();
        $associations = collect();
        $localId = null;
        $dealIds = [];
        $displayName = '';
        $source = '';

        if ($kind === 'hubspot') {
            $company = DB::table('hubspot_companies')->where('id', $id)->first();
            if ($company === null) {
                return null;
            }
            $displayName = (string) ($company->name ?: 'Empresa HubSpot '.$company->hubspot_id);
            $source = 'Empresa · HubSpot';
            $hubspots = collect([$company]);
            if ($company->company_id !== null && ! HubSpotCompany::isUnsafeFiscalMatchSource($company->match_source)) {
                $localId = (int) $company->company_id;
            }
        } elseif ($kind === 'local') {
            $company = DB::table('companies')->where('id', $id)->first(['id', 'corporate_name', 'cnpj_root']);
            if ($company === null) {
                return null;
            }
            $displayName = (string) $company->corporate_name;
            $source = 'Empresa · Receita/Prospector · raiz CNPJ '.(string) $company->cnpj_root;
            $localId = $id;
            $hubspots = DB::table('hubspot_companies')->where('company_id', $id)->get()
                ->reject(static fn (object $h): bool => HubSpotCompany::isUnsafeFiscalMatchSource($h->match_source))
                ->values();
        } else {
            $deal = DB::table('hubspot_deals')->where('id', $id)->first(['id', 'hubspot_id', 'name', 'stage_label']);
            if ($deal === null) {
                return null;
            }
            $displayName = (string) ($deal->name ?: 'Negócio HubSpot '.$deal->hubspot_id);
            $source = 'Negócio · HubSpot · '.(string) ($deal->stage_label ?: 'Etapa não informada');
            $dealIds[] = $id;
            $associations = DB::table('hubspot_company_deal as l')
                ->join('hubspot_companies as h', 'h.id', '=', 'l.hubspot_company_id')
                ->where('l.hubspot_deal_id', $id)->orderByDesc('l.is_primary')
                ->get(['h.id', 'h.hubspot_id', 'h.name', 'h.domain', 'h.company_id', 'h.match_source', 'l.is_primary']);
            // Negócio com várias Companies não propaga CNPJ ou telefones de
            // empresas secundárias. Só a primary explícita ou a única Company.
            $primary = $associations->where('is_primary', true);
            if ($primary->count() === 1) {
                $hubspots = collect([$primary->first()]);
            } elseif ($associations->count() === 1) {
                $hubspots = collect([$associations->first()]);
            }
            $trusted = $hubspots->first();
            if ($trusted !== null && $trusted->company_id !== null
                && ! HubSpotCompany::isUnsafeFiscalMatchSource($trusted->match_source)) {
                $localId = (int) $trusted->company_id;
            }
        }

        if ($localId !== null) {
            foreach (DB::table('commercial_contacts')->where('company_id', $localId)->pluck('id') as $contactId) {
                $sourceKeys['manual:'.$contactId] = true;
            }
            foreach (DB::table('establishments')->where('company_id', $localId)->pluck('uuid') as $uuid) {
                $sourceKeys['receita:'.$uuid] = true;
            }
            foreach (DB::table('company_hubspot_leads as l')
                ->join('hubspot_contacts as hc', 'hc.id', '=', 'l.hubspot_contact_id')
                ->where('l.company_id', $localId)->whereNotNull('l.hubspot_contact_id')
                ->pluck('hc.hubspot_id') as $hubId) {
                $sourceKeys['hubspot:'.$hubId] = true;
            }
        }

        // Contatos ligados diretamente à Company escolhida, mais contatos
        // explicitamente ligados a um Deal comercial da Company.
        foreach ($hubspots as $h) {
            foreach (DB::table('hubspot_company_contact as l')
                ->join('hubspot_contacts as hc', 'hc.id', '=', 'l.hubspot_contact_id')
                ->where('l.hubspot_company_id', $h->id)->pluck('hc.hubspot_id') as $hubId) {
                $sourceKeys['hubspot:'.$hubId] = true;
            }
            foreach (DB::table('commercial_contacts')->where('hubspot_company_external_id', $h->hubspot_id)->pluck('id') as $contactId) {
                $sourceKeys['manual:'.$contactId] = true;
            }
            if ($kind !== 'deal') {
                $linkedDeals = DB::table('hubspot_company_deal as l')
                    ->where('l.hubspot_company_id', $h->id)
                    ->where(static function ($q): void {
                        $q->where('l.is_primary', true)->orWhereNotExists(static function ($sub): void {
                            $sub->selectRaw('1')->from('hubspot_company_deal as other')
                                ->whereColumn('other.hubspot_deal_id', 'l.hubspot_deal_id')
                                ->whereColumn('other.hubspot_company_id', '<>', 'l.hubspot_company_id');
                        });
                    })->pluck('l.hubspot_deal_id')->all();
                $dealIds = array_merge($dealIds, $linkedDeals);
            }
        }
        foreach (array_chunk(array_unique($dealIds), 500) as $chunk) {
            foreach (DB::table('hubspot_deal_contact as l')
                ->join('hubspot_contacts as hc', 'hc.id', '=', 'l.hubspot_contact_id')
                ->whereIn('l.hubspot_deal_id', $chunk)->pluck('hc.hubspot_id') as $hubId) {
                $sourceKeys['hubspot:'.$hubId] = true;
            }
        }

        $phones = collect();
        // Uma consulta por lote de 500 CHAVES, nunca uma consulta por contato.
        foreach (array_chunk(array_keys($sourceKeys), 500) as $chunk) {
            $phones = $phones->concat(DB::table('whatsapp_phone_sources as s')
                ->join('whatsapp_phone_numbers as n', 'n.id', '=', 's.phone_id')
                ->whereIn('s.source_key', $chunk)->distinct()
                ->get(['n.id', 'n.e164', 'n.digits', 'n.kind', 'n.status', 'n.contacts_count']));
        }
        $phones = $phones->unique('id')->values();

        // Só expor um domínio quando existe UMA única Company HubSpot
        // contextual: múltiplos domínios não significam mesma empresa.
        $domain = null;
        $siteHubspotId = null;
        if ($hubspots->count() === 1) {
            $site = $hubspots->first();
            $domain = SafePublicWhatsappWebsiteFetcher::domain((string) $site->domain);
            if ($domain !== null) {
                $siteHubspotId = (int) $site->id;
            }
        }
        $linkedNames = $kind === 'deal'
            ? $associations->pluck('name')->filter()->unique()->values()->all()
            : $hubspots->pluck('name')->filter()->unique()->values()->all();

        return [
            'key' => $key,
            'name' => $displayName,
            'source' => $source,
            'domain' => $domain,
            'site_hubspot_company_id' => $siteHubspotId,
            'linked_companies' => $linkedNames,
            'phones' => $phones->take(100)->values(),
            'phone_total' => $phones->count(),
            'confirmed_total' => $phones->where('status', 'yes')->count(),
        ];
    }
}
