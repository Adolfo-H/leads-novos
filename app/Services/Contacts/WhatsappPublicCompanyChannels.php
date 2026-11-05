<?php

namespace App\Services\Contacts;

use App\Models\HubSpotCompany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Canal público associado a uma empresa de domínio explícito do HubSpot.
 * NÃO cria pessoas, NÃO escreve no HubSpot, NÃO inventa negócios e
 * NÃO converte link publicado em confirmação de conta WhatsApp ativa.
 */
final class WhatsappPublicCompanyChannels
{
    /** Atualiza canais de um domínio, de forma idempotente. Retorna novas associações. */
    public function syncDomain(string $domain): int
    {
        $domain = SafePublicWhatsappWebsiteFetcher::domain($domain) ?? '';
        if ($domain === '') {
            return 0;
        }

        $evidences = DB::table('whatsapp_public_site_evidences')
            ->where('domain', $domain)->get(['id', 'e164', 'first_seen_at', 'last_seen_at']);
        if ($evidences->isEmpty()) {
            return 0;
        }

        $companies = DB::table('hubspot_companies')
            ->whereNotNull('domain')->where('domain', '<>', '')
            ->get(['id', 'domain', 'company_id', 'match_source'])
            ->filter(static fn (object $company): bool => SafePublicWhatsappWebsiteFetcher::domain((string) $company->domain) === $domain);

        if ($companies->isEmpty()) {
            return 0; // Sem empresa explícita: manter evidência sem inventar cadastro.
        }

        $now = now('UTC');
        $rows = [];
        foreach ($companies as $company) {
            $fiscalId = $company->company_id !== null
                && ! HubSpotCompany::isUnsafeFiscalMatchSource($company->match_source)
                ? (int) $company->company_id : null;
            foreach ($evidences as $evidence) {
                $rows[] = [
                    'evidence_id' => (int) $evidence->id,
                    'hubspot_company_id' => (int) $company->id,
                    'company_id' => $fiscalId,
                    'e164' => (string) $evidence->e164,
                    'first_seen_at' => $evidence->first_seen_at,
                    'last_seen_at' => $evidence->last_seen_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $before = (int) DB::table('whatsapp_public_company_channels')
            ->whereIn('evidence_id', $evidences->pluck('id')->all())
            ->whereIn('hubspot_company_id', $companies->pluck('id')->all())
            ->count();
        foreach (array_chunk($rows, 150) as $chunk) {
            DB::table('whatsapp_public_company_channels')->upsert(
                $chunk,
                ['evidence_id', 'hubspot_company_id'],
                ['company_id', 'e164', 'last_seen_at', 'updated_at'],
            );
        }
        // EC_WA_PUBLIC_DIRECTORY_V14_SYNC: novo telefone publicado entra no índice local
        // imediatamente; não altera a confirmação de WhatsApp e não cria pessoas.
        app(WhatsappPublicPhoneIndexerV14::class)->syncDomain($domain);

        return max(0, count($rows) - $before);
    }

    /** @return array{domains:int,created:int,channels:int} */
    public function backfill(): array
    {
        $seen = [];
        $created = 0;
        DB::table('whatsapp_public_site_evidences')->orderBy('id')
            ->chunkById(250, function ($items) use (&$seen, &$created): void {
                foreach ($items as $item) {
                    $domain = (string) $item->domain;
                    if (isset($seen[$domain])) {
                        continue;
                    }
                    $seen[$domain] = true;
                    $created += $this->syncDomain($domain);
                }
            });

        return [
            'domains' => count($seen),
            'created' => $created,
            'channels' => (int) DB::table('whatsapp_public_company_channels')->count(),
        ];
    }

    /** @return Collection<int,\stdClass> */
    public function forCompany(int $hubspotCompanyId, int $limit = 100): Collection
    {
        return DB::table('whatsapp_public_company_channels as c')
            ->join('whatsapp_public_site_evidences as e', 'e.id', '=', 'c.evidence_id')
            ->leftJoin('whatsapp_phone_numbers as n', 'n.id', '=', 'e.global_phone_id')
            ->where('c.hubspot_company_id', $hubspotCompanyId)
            ->orderByDesc('c.last_seen_at')
            ->limit(max(1, min(100, $limit)))
            ->get([
                'c.id', 'c.e164', 'c.last_seen_at', 'c.company_id',
                'e.source_url', 'e.wa_url', 'e.link_label',
                'e.phone_id', 'e.global_phone_id',
                'n.status as registry_status',
            ]);
    }

    /**
     * Negócios de fato associados à empresa no HubSpot, não ao telefone.
     *
     * @return Collection<int, \stdClass>
     */
    public function companyDeals(int $hubspotCompanyId, int $limit = 8): Collection
    {
        return DB::table('hubspot_company_deal as link')
            ->join('hubspot_deals as deal', 'deal.id', '=', 'link.hubspot_deal_id')
            ->where('link.hubspot_company_id', $hubspotCompanyId)
            ->orderByDesc('deal.id')
            ->limit(max(1, min(20, $limit)))
            ->get(['deal.id', 'deal.name', 'deal.hubspot_id', 'deal.stage_label', 'deal.pipeline_label']);
    }
}
