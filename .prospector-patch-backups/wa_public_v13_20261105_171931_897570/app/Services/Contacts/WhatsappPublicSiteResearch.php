<?php

namespace App\Services\Contacts;

use App\Jobs\ResearchWhatsappPublicCompanySite;
use App\Models\HubSpotCompany;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enriquecimento por links de WhatsApp publicados em domínios registrados
 * no HubSpot. Nunca conecta uma conta WhatsApp nem modifica confirmações.
 */
final class WhatsappPublicSiteResearch
{
    /** @return array<string,int> */
    public function metrics(): array
    {
        $totals = DB::table('whatsapp_public_site_checks')
            ->selectRaw("COUNT(*) AS sites")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END), 0) AS done")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'queued' OR status = 'running' THEN 1 ELSE 0 END), 0) AS running")
            ->first();
        $links = DB::table('whatsapp_public_site_evidences')
            ->selectRaw('COUNT(*) AS links')
            ->selectRaw('COALESCE(SUM(CASE WHEN phone_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS matched_links')
            ->selectRaw('COALESCE(SUM(CASE WHEN global_phone_id IS NULL AND phone_id IS NULL THEN 1 ELSE 0 END), 0) AS new_numbers')
            ->selectRaw('COUNT(DISTINCT global_phone_id) AS registered_anywhere')
            ->selectRaw('COUNT(DISTINCT phone_id) AS unique_registered')
            ->first();

        return [
            'sites' => (int) ($totals->sites ?? 0),
            'done' => (int) ($totals->done ?? 0),
            'running' => (int) ($totals->running ?? 0),
            'links' => (int) ($links->links ?? 0),
            'matched' => (int) ($links->unique_registered ?? 0),
            'indexed' => (int) ($links->registered_anywhere ?? 0),
            'new' => (int) ($links->new_numbers ?? 0),
        ];
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    public function recent(int $limit = 8)
    {
        return DB::table('whatsapp_public_site_evidences as e')
            ->leftJoin('whatsapp_phone_numbers as n', 'n.id', '=', 'e.phone_id')
            ->orderByDesc('e.last_seen_at')->limit(max(1, min(20, $limit)))
            ->get(['e.*', 'n.kind as number_kind', 'n.status as contact_status']);
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    public function pageEvidence(array $phoneIds)
    {
        if ($phoneIds === []) {
            return collect();
        }
        return DB::table('whatsapp_public_site_evidences')
            ->whereIn('phone_id', $phoneIds)
            ->orderByDesc('last_seen_at')->get();
    }

    public function activeRun(): ?object
    {
        return DB::table('whatsapp_public_site_runs')->latest('id')->first();
    }

    public function settings(): object
    {
        return DB::table('whatsapp_public_site_settings')->where('id', 1)->firstOrFail();
    }

    public function saveSettings(User $actor, bool $enabled, int $sitesPerDay): void
    {
        abort_unless($actor->isCommercialManager(), 403);
        if (! in_array($sitesPerDay, [5, 10, 20], true)) {
            throw ValidationException::withMessages(['public_site' => 'Escolha 5, 10 ou 20 sites por dia.']);
        }
        DB::table('whatsapp_public_site_settings')->where('id', 1)->update([
            'auto_enabled' => $enabled, 'sites_per_day' => $sitesPerDay,
            'configured_by' => $actor->id, 'updated_at' => now('UTC'),
        ]);
    }

    // EC_WA_PUBLIC_COMPANY_V11: empresa selecionada, sem adivinhar domínios por nome.
    public function companyCandidates(string $search, int $limit = 12): \Illuminate\Support\Collection
    {
        $search = mb_substr(trim($search), 0, 100);
        if (mb_strlen($search) < 2) {
            return collect();
        }
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
        $like = '%'.$escaped.'%';
        return DB::table('hubspot_companies')->whereNotNull('domain')->where('domain', '<>', '')
            ->where(static function ($q) use ($like): void {
                $q->whereRaw("LOWER(COALESCE(name, '')) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(domain, '')) LIKE ? ESCAPE '!'", [$like]);
            })->orderBy('name')->limit(70)->get(['id', 'hubspot_id', 'name', 'domain'])
            ->filter(static fn ($c): bool => SafePublicWhatsappWebsiteFetcher::domain($c->domain) !== null)
            ->take(max(1, min(20, $limit)))->values();
    }

    /** @return array<string,mixed>|null */
    public function companySnapshot(int $companyId): ?array
    {
        $company = DB::table('hubspot_companies')->where('id', $companyId)
            ->first(['id','hubspot_id','name','domain']);
        $domain = $company === null ? null : SafePublicWhatsappWebsiteFetcher::domain($company->domain);
        if ($domain === null) { return null; }
        $related = $this->relatedNumbers($domain);
        $ids = array_values(array_unique(array_values($related['phones'])));
        $evidences = DB::table('whatsapp_public_site_evidences')->where('domain', $domain)
            ->orderByDesc('last_seen_at')->limit(100)->get();
        $published = array_fill_keys($evidences->pluck('phone_id')->filter()->map(fn ($n) => (int) $n)->all(), true);
        $phones = $ids === [] ? collect() : DB::table('whatsapp_phone_numbers')
            ->whereIn('id', $ids)->orderByRaw("CASE status WHEN 'unknown' THEN 0 ELSE 1 END")
            ->orderBy('id')->limit(80)
            ->get(['id', 'e164', 'kind', 'status', 'contacts_count'])
            ->map(static function ($row) use ($published) {
                $row->published_here = isset($published[(int) $row->id]);
                return $row;
            });
        $confirmed = $ids === [] ? 0 : DB::table('whatsapp_phone_numbers')
            ->whereIn('id', $ids)->where('status', 'yes')->count();
        return [
            'company'=>$company, 'domain'=>$domain,
            'phones'=>$phones, 'phone_total'=>count($ids),
            'confirmed_total'=>$confirmed, 'direct_total'=>count($published),
            'evidences'=>$evidences,
            'check'=>DB::table('whatsapp_public_site_checks')->where('domain', $domain)->first(),
        ];
    }

    /**
     * Pesquisa explicitamente uma empresa escolhida pelo gestor; reusa resultado
     * recente em vez de repetir coleta. Não faz rede no request Livewire.
     */
    public function queueCompany(User $actor, int $companyId): ?int
    {
        abort_unless($actor->isCommercialManager(), 403);
        $company = DB::table('hubspot_companies')->where('id', $companyId)
            ->first(['id', 'domain']);
        $domain = $company === null ? null : SafePublicWhatsappWebsiteFetcher::domain($company->domain);
        if ($domain === null) {
            throw ValidationException::withMessages(['public_site' => 'Selecione uma empresa com domínio público válido no HubSpot.']);
        }
        $run = DB::transaction(function () use ($actor, $domain): ?int {
            DB::table('whatsapp_public_site_settings')->where('id', 1)->lockForUpdate()->first();
            if (DB::table('whatsapp_public_site_runs')->whereIn('status', ['queued','running'])->exists()) {
                throw ValidationException::withMessages(['public_site' => 'Aguarde a pesquisa atual concluir antes de iniciar outra.']);
            }
            $check = DB::table('whatsapp_public_site_checks')->where('domain', $domain)->first();
            if ($check && $check->checked_at && \Carbon\Carbon::parse($check->checked_at)->gt(now('UTC')->subMinutes(10))) {
                return null; // Já foi consultado recentemente; a tela usa os dados salvos.
            }
            $id = (int) DB::table('whatsapp_public_site_runs')->insertGetId([
                'created_by'=>$actor->id, 'status'=>'queued', 'requested_sites'=>1,
                'created_at'=>now('UTC'), 'updated_at'=>now('UTC'),
            ]);
            DB::table('whatsapp_public_site_checks')->upsert([[
                'domain'=>$domain, 'status'=>'queued', 'run_id'=>$id,
                'pages_checked'=>0, 'links_found'=>0, 'error_code'=>null,
                'created_at'=>now('UTC'), 'updated_at'=>now('UTC'),
            ]], ['domain'], ['status','run_id','pages_checked','links_found','error_code','updated_at']);
            return $id;
        }, 2);
        if ($run !== null) {
            ResearchWhatsappPublicCompanySite::dispatch($run, $domain)->onQueue('default');
        }
        return $run;
    }

    /**
     * Atualiza vínculos de pesquisas JÁ salvas, sem visitar sites nem alterar
     * qualquer status de WhatsApp. Pode ser executado diariamente.
     * @return array{seen:int,global:int,direct:int,changed:int}
     */
    public function reconcileExisting(bool $apply = false): array
    {
        $stats = ['seen'=>0,'global'=>0,'direct'=>0,'changed'=>0];
        DB::table('whatsapp_public_site_evidences')->orderBy('id')->chunkById(250,
            function ($rows) use (&$stats, $apply): void {
                foreach ($rows->groupBy('domain') as $domain=>$group) {
                    $direct = $this->relatedNumbers((string) $domain)['phones'];
                    $global = DB::table('whatsapp_phone_numbers')
                        ->whereIn('e164', $group->pluck('e164')->all())
                        ->where('contacts_count', '>', 0)->pluck('id', 'e164')->all();
                    foreach ($group as $item) {
                        ++$stats['seen'];
                        $directId = $direct[$item->e164] ?? null;
                        $globalId = $global[$item->e164] ?? null;
                        if ($directId !== null) ++$stats['direct'];
                        if ($globalId !== null) ++$stats['global'];
                        if ((int) ($item->phone_id ?? 0) !== (int) ($directId ?? 0)
                            || (int) ($item->global_phone_id ?? 0) !== (int) ($globalId ?? 0)) {
                            ++$stats['changed'];
                            if ($apply) DB::table('whatsapp_public_site_evidences')->where('id', $item->id)
                                ->update(['phone_id'=>$directId, 'global_phone_id'=>$globalId, 'updated_at'=>now('UTC')]);
                        }
                    }
                }
            });
        return $stats;
    }

    /**
     * Cria um lote SEM fazer chamadas de rede na requisição Livewire.
     * Um job por site evita estourar timeout do worker padrão.
     */
    public function queueRun(User $actor, int $maxSites = 5): int
    {
        abort_unless($actor->isCommercialManager(), 403);
        if ($maxSites < 1 || $maxSites > 20) {
            throw ValidationException::withMessages(['public_site' => 'Selecione de 1 a 20 sites por lote.']);
        }

        $run = DB::transaction(function () use ($actor, $maxSites): array {
            // Serializa dois cliques simultâneos e o scheduler usando a linha singleton.
            DB::table('whatsapp_public_site_settings')->where('id', 1)->lockForUpdate()->first();
            if (DB::table('whatsapp_public_site_runs')->whereIn('status', ['queued', 'running'])->exists()) {
                throw ValidationException::withMessages(['public_site' => 'Já existe uma pesquisa em andamento.']);
            }
            $skip = DB::table('whatsapp_public_site_checks')
                ->where(function ($q): void {
                    $q->whereIn('status', ['queued', 'running'])
                        ->orWhere('checked_at', '>=', now('UTC')->subDays(30));
                })->pluck('domain')->flip();
            $domains = [];
            DB::table('hubspot_companies')->whereNotNull('domain')
                ->where('domain', '<>', '')
                ->orderBy('id')->select('id', 'domain')
                ->chunkById(700, static function ($rows) use (&$domains, $skip, $maxSites): bool {
                    foreach ($rows as $company) {
                        $domain = SafePublicWhatsappWebsiteFetcher::domain((string) $company->domain);
                        if ($domain !== null && ! $skip->has($domain) && ! isset($domains[$domain])) {
                            $domains[$domain] = $domain;
                            if (count($domains) >= $maxSites) {
                                return false;
                            }
                        }
                    }
                    return true;
                });
            if ($domains === []) {
                throw ValidationException::withMessages([
                    'public_site' => 'Nenhum domínio novo elegível. Os já pesquisados aguardam 30 dias para nova consulta.',
                ]);
            }
            $id = (int) DB::table('whatsapp_public_site_runs')->insertGetId([
                'created_by' => $actor->id, 'status' => 'queued',
                'requested_sites' => count($domains),
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            foreach ($domains as $domain) {
                DB::table('whatsapp_public_site_checks')->upsert([[
                    'domain' => $domain, 'status' => 'queued', 'run_id' => $id,
                    'pages_checked' => 0, 'links_found' => 0, 'error_code' => null,
                    'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]], ['domain'], ['status', 'run_id', 'pages_checked', 'links_found', 'error_code', 'updated_at']);
            }
            return ['id' => $id, 'domains' => array_values($domains)];
        }, 2);
        foreach ($run['domains'] as $domain) {
            ResearchWhatsappPublicCompanySite::dispatch($run['id'], $domain)->onQueue('default');
        }

        return $run['id'];
    }

    /** @return array{status:string,links:int,matched:int} */
    public function researchDomain(int $runId, string $domain): array
    {
        $check = DB::table('whatsapp_public_site_checks')->where('domain', $domain)
            ->where('run_id', $runId)->first();
        if ($check === null || $check->status !== 'queued') {
            return ['status' => 'skipped', 'links' => 0, 'matched' => 0];
        }
        DB::table('whatsapp_public_site_checks')->where('id', $check->id)->update([
            'status' => 'running', 'updated_at' => now('UTC'),
        ]);
        DB::table('whatsapp_public_site_runs')->where('id', $runId)->where('status', 'queued')
            ->update(['status' => 'running', 'updated_at' => now('UTC')]);
        $links = [];
        $pagesChecked = 0;
        $matched = 0;
        $status = 'done';
        $error = null;
        try {
            $fetcher = app()->bound(PublicWhatsappWebsiteFetcher::class)
                ? app(PublicWhatsappWebsiteFetcher::class)
                : app(SafePublicWhatsappWebsiteFetcher::class);
            $extractor = app(PublicWhatsappLinkExtractor::class);
            $first = $fetcher->get($domain, '/');
            if ($first === null) {
                throw new \RuntimeException('site_sem_html');
            }
            $pages = [$first];
            $additional = $extractor->contactPages($first['html'], $domain);
            if ($additional !== []) {
                $page = $fetcher->get($domain, $additional[0]);
                if ($page !== null) {
                    $pages[] = $page;
                }
            }
            $related = $this->relatedNumbers($domain);
            $label = $related['company_name'];
            foreach ($pages as $page) {
                $pagesChecked++;
                foreach ($extractor->extract($page['html']) as $link) {
                    $link['source_url'] = $page['url'];
                    $links[$link['e164']] = $link; // Uma evidência por telefone + domínio.
                }
            }
            // EC_WA_PUBLIC_COMPANY_V11: cruza após extrair os links do site,
            // uma única consulta para o lote, sem supor empresas equivalentes.
            $globalPhones = DB::table('whatsapp_phone_numbers')
                ->whereIn('e164', array_map('strval', array_keys($links)))
                ->where('contacts_count', '>', 0)
                ->pluck('id', 'e164')->all();
            foreach ($links as $e164 => $link) {
                $phoneId = $related['phones'][$e164] ?? null;
                if ($phoneId !== null) {
                    $matched++;
                }
                $existing = DB::table('whatsapp_public_site_evidences')
                    ->where('domain', $domain)->where('e164', $e164)->first(['id']);
                $row = [
                    'domain' => $domain, 'e164' => $e164,
                    'source_url' => $link['source_url'], 'wa_url' => $link['wa_url'],
                    'link_label' => $link['label'], 'company_label' => $label,
                    'phone_id' => $phoneId,
                    'global_phone_id' => $globalPhones[$e164] ?? null,
                    'last_seen_at' => now('UTC'), 'updated_at' => now('UTC'),
                ];
                if ($existing) {
                    DB::table('whatsapp_public_site_evidences')->where('id', $existing->id)->update($row);
                } else {
                    DB::table('whatsapp_public_site_evidences')->insert($row + [
                        'first_seen_at' => now('UTC'), 'created_at' => now('UTC'),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Não armazenar erro externo que possa conter PII, HTML ou URL com parâmetros.
            $status = 'error';
            $error = $e->getMessage() === 'site_sem_html' ? 'site_indisponivel' : 'falha_na_leitura';
        }
        DB::transaction(function () use ($check, $runId, $domain, $status, $error, $pagesChecked, $links, $matched): void {
            $current = DB::table('whatsapp_public_site_checks')->where('id', $check->id)
                ->where('run_id', $runId)->lockForUpdate()->first();
            if ($current === null || $current->status !== 'running') {
                return;
            }
            DB::table('whatsapp_public_site_checks')->where('id', $check->id)->update([
                'status' => $status, 'error_code' => $error, 'pages_checked' => $pagesChecked,
                'links_found' => count($links), 'checked_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            DB::table('whatsapp_public_site_runs')->where('id', $runId)->incrementEach([
                'processed_sites' => 1, 'found_links' => count($links), 'matched_numbers' => $matched,
            ], ['updated_at' => now('UTC')]);
            $run = DB::table('whatsapp_public_site_runs')->where('id', $runId)->lockForUpdate()->first();
            if ($run && $run->processed_sites >= $run->requested_sites) {
                DB::table('whatsapp_public_site_runs')->where('id', $runId)->update([
                    'status' => 'completed', 'finished_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            }
        }, 2);

        return ['status' => $status, 'links' => count($links), 'matched' => $matched];
    }

    /**
     * Retorna SOMENTE vínculos diretos com o domínio registrado no HubSpot.
     * Não presume que domínio identifique CNPJ nem propaga links entre empresas.
     *
     * @return array{company_name:?string,phones:array<string,int>}
     */
    private function relatedNumbers(string $domain): array
    {
        $companies = DB::table('hubspot_companies')
            ->whereNotNull('domain')->select(['id', 'hubspot_id', 'domain', 'company_id', 'match_source', 'name'])
            ->get()->filter(static fn ($c): bool => SafePublicWhatsappWebsiteFetcher::domain($c->domain) === $domain);
        $companyIds = $companies->pluck('id')->map(static fn ($i): int => (int) $i)->all();
        if ($companyIds === []) {
            return ['company_name' => null, 'phones' => []];
        }
        $keys = [];
        foreach (DB::table('hubspot_company_contact as l')
            ->join('hubspot_contacts as h', 'h.id', '=', 'l.hubspot_contact_id')
            ->whereIn('l.hubspot_company_id', $companyIds)->distinct()->pluck('h.hubspot_id') as $id) {
            $keys['hubspot:'.$id] = true;
        }
        foreach (DB::table('commercial_contacts')->whereIn('hubspot_company_external_id', $companies->pluck('hubspot_id')->all())
            ->pluck('id') as $id) {
            $keys['manual:'.$id] = true;
        }
        $localCompanyIds = $companies->filter(static fn ($c): bool => $c->company_id !== null
            && ! HubSpotCompany::isUnsafeFiscalMatchSource($c->match_source))
            ->pluck('company_id')->unique()->all();
        if ($localCompanyIds !== []) {
            foreach (DB::table('commercial_contacts')->whereIn('company_id', $localCompanyIds)->pluck('id') as $id) {
                $keys['manual:'.$id] = true;
            }
            foreach (DB::table('establishments')->whereIn('company_id', $localCompanyIds)->pluck('uuid') as $uuid) {
                $keys['receita:'.$uuid] = true;
            }
        }
        $phones = [];
        foreach (array_chunk(array_keys($keys), 500) as $batch) {
            $rows = DB::table('whatsapp_phone_sources as s')
                ->join('whatsapp_phone_numbers as n', 'n.id', '=', 's.phone_id')
                ->whereIn('s.source_key', $batch)
                ->whereNotNull('n.e164')->get(['n.id', 'n.e164']);
            foreach ($rows as $row) {
                $phones[(string) $row->e164] = (int) $row->id;
            }
        }
        return ['company_name' => mb_substr((string) ($companies->first()->name ?? ''), 0, 180) ?: null, 'phones' => $phones];
    }

    /** Scheduler: a programação exige opt-in e gestor ainda autorizado. */
    public function planDaily(): bool
    {
        $now = CarbonImmutable::now('America/Sao_Paulo');
        if ($now->isWeekend()) {
            return false;
        }
        $settings = $this->settings();
        if (! $settings->auto_enabled || $settings->last_queued_on === $now->toDateString()) {
            return false;
        }
        $actor = User::query()->find((int) $settings->configured_by);
        if (! $actor || ! $actor->isCommercialManager()) {
            return false;
        }
        try {
            $this->queueRun($actor, (int) $settings->sites_per_day);
            DB::table('whatsapp_public_site_settings')->where('id', 1)->update([
                'last_queued_on' => $now->toDateString(), 'updated_at' => now('UTC'),
            ]);
            return true;
        } catch (ValidationException) {
            return false;
        }
    }
}
