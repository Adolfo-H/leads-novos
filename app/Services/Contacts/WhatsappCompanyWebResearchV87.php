<?php

namespace App\Services\Contacts;

use App\Jobs\ResearchWhatsappCompanyWebV87;
use App\Models\User;
use App\Support\TextNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * V87: procura canais públicos fora do CRM SEM associá-los automaticamente.
 * Consulta o site explícito, se houver, e índices oficiais Tavily/Brave.
 * Telefones públicos sem link inequívoco de WhatsApp são apenas candidatos.
 * Nunca altera contatos/negócios, telefones originais ou status WhatsApp.
 */
final class WhatsappCompanyWebResearchV87
{
    private const TABLE_RUNS = 'whatsapp_company_web_runs_v87';

    private const TABLE_FINDINGS = 'whatsapp_company_web_findings_v87';

    /** @return array{tavily:bool,brave:bool} */
    public function providers(): array
    {
        return [
            'tavily' => trim((string) config('whatsapp_public_search.tavily_token', '')) !== '',
            'brave' => trim((string) config('whatsapp_public_search.brave_token', '')) !== '',
        ];
    }

    /** @return \stdClass|null */
    public function latest(string $targetKey): ?object
    {
        return DB::table(self::TABLE_RUNS)->where('target_key', $targetKey)
            ->orderByDesc('id')->first();
    }

    /** @return Collection<int, \stdClass> */
    public function findings(string $targetKey, int $max = 100): Collection
    {
        $latestWithResults = DB::table(self::TABLE_RUNS)
            ->where('target_key', $targetKey)->where('findings_count', '>', 0)
            ->orderByDesc('id')->value('id');
        if ($latestWithResults === null) {
            /** @var Collection<int, \stdClass> $empty */
            $empty = collect();

            return $empty;
        }

        return DB::table(self::TABLE_FINDINGS)->where('run_id', $latestWithResults)
            ->orderByRaw("CASE WHEN kind = 'explicit_whatsapp_link' THEN 0 ELSE 1 END")
            ->orderBy('phone_e164')->limit(max(1, min(100, $max)))->get();
    }

    public function queue(User $actor, string $targetKey): int
    {
        abort_unless($actor->isCommercialManager(), 403);
        if (preg_match('/^(?:brand:[A-Z0-9]{7,40}|(?:hubspot|deal|local):[1-9][0-9]{0,17})$/D', $targetKey) !== 1) {
            throw ValidationException::withMessages(['wa87_search' => 'Selecione novamente uma empresa da lista.']);
        }

        $snapshot = app(WhatsappBusinessDirectoryV86::class)->snapshot($targetKey);
        if ($snapshot === null) {
            throw ValidationException::withMessages(['wa87_search' => 'Empresa não encontrada no cadastro local. Atualize a pesquisa.']);
        }
        $companyName = trim((string) ($snapshot['name'] ?? ''));
        $domain = SafePublicWhatsappWebsiteFetcher::domain((string) ($snapshot['domain'] ?? ''));
        $providers = $this->providers();
        if ($domain === null && ! $providers['tavily'] && ! $providers['brave']) {
            throw ValidationException::withMessages([
                'wa87_search' => 'Sem site cadastrado e sem API de busca pública configurada. Ative TAVILY_API_KEY ou WA_PUBLIC_BRAVE_SEARCH_API_KEY no servidor.',
            ]);
        }
        if (mb_strlen($companyName) < 3) {
            throw ValidationException::withMessages(['wa87_search' => 'Informe uma empresa com nome completo no cadastro.']);
        }

        // A trava evita dois cliques e permite recuperar filas antigas sem apagar resultados.
        $runId = DB::transaction(static function () use ($targetKey, $companyName, $domain, $actor): int {
            $last = DB::table(self::TABLE_RUNS)->where('target_key', $targetKey)
                ->orderByDesc('id')->lockForUpdate()->first();
            if ($last !== null) {
                $state = (string) $last->status;
                if (in_array($state, ['queued', 'running'], true)) {
                    if (Carbon::parse((string) $last->updated_at, 'UTC')->gt(now('UTC')->subMinutes(6))) {
                        throw ValidationException::withMessages([
                            'wa87_search' => 'A pesquisa ainda está na fila ou em execução. Aguarde o resultado antes de tentar novamente.',
                        ]);
                    }
                    DB::table(self::TABLE_RUNS)->where('id', $last->id)
                        ->whereIn('status', ['queued', 'running'])
                        ->update(['status' => 'timed_out', 'error_code' => 'job_timeout',
                            'finished_at' => now('UTC'), 'updated_at' => now('UTC')]);
                } elseif ($state === 'completed' && Carbon::parse((string) ($last->finished_at ?? $last->updated_at), 'UTC')->gt(now('UTC')->subMinutes(10))) {
                    // Evitar gastar créditos com consultas repetidas em poucos minutos.
                    return (int) $last->id;
                }
            }

            return (int) DB::table(self::TABLE_RUNS)->insertGetId([
                'target_key' => $targetKey,
                'company_label' => mb_substr($companyName, 0, 180),
                'domain' => $domain,
                'created_by' => $actor->id,
                'status' => 'queued', 'findings_count' => 0,
                'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        }, 3);

        // Só enfileira se a transação criou uma nova execução.
        if (DB::table(self::TABLE_RUNS)->where('id', $runId)->where('status', 'queued')->exists()) {
            try {
                ResearchWhatsappCompanyWebV87::dispatch($runId)->onConnection('redis')->onQueue('default');
            } catch (Throwable $error) {
                report($error);
                $this->markFailed($runId);
                throw ValidationException::withMessages([
                    'wa87_search' => 'Não foi possível enfileirar a pesquisa. Confira Redis e o worker default.',
                ]);
            }
        }

        return $runId;
    }

    /** @return array{status:string,findings:int} */
    public function execute(int $runId): array
    {
        $run = DB::table(self::TABLE_RUNS)->where('id', $runId)->first();
        if ($run === null || $run->status !== 'queued') {
            return ['status' => 'skipped', 'findings' => 0];
        }
        $changed = DB::table(self::TABLE_RUNS)->where('id', $runId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now('UTC'), 'updated_at' => now('UTC')]);
        if ($changed !== 1) {
            return ['status' => 'skipped', 'findings' => 0];
        }

        $name = (string) $run->company_label;
        $domain = SafePublicWhatsappWebsiteFetcher::domain((string) ($run->domain ?? ''));
        $channels = [];
        $success = false;
        $statuses = [];
        try {
            if ($domain !== null) {
                [$siteFound, $siteOk] = $this->scanSite($domain);
                array_push($channels, ...$siteFound);
                $statuses['site'] = $siteOk ? 'consultado' : 'indisponivel';
                $success = $siteOk;
            } else {
                $statuses['site'] = 'sem_dominio';
            }

            $providers = $this->providers();
            if ($providers['tavily']) {
                [$found, $ok, $reason] = $this->scanTavily($name, $domain);
                array_push($channels, ...$found);
                $statuses['tavily'] = $reason;
                $success = $success || $ok;
            } else {
                $statuses['tavily'] = 'nao_configurado';
            }
            if ($providers['brave']) {
                [$found, $ok, $reason] = $this->scanBrave($name, $domain);
                array_push($channels, ...$found);
                $statuses['brave'] = $reason;
                $success = $success || $ok;
            } else {
                $statuses['brave'] = 'nao_configurado';
            }

            $seen = [];
            // Um mesmo telefone/fonte pode aparecer no HTML e no snippet.
            // O link explícito prevalece sobre o telefone textual.
            $unique = [];
            foreach ($channels as $item) {
                $identity = $item['e164'].'|'.$item['source_url'];
                if (! isset($unique[$identity]) || $item['kind'] === 'explicit_whatsapp_link') {
                    $unique[$identity] = $item;
                }
            }
            $stamp = now('UTC');
            foreach ($unique as $item) {
                $hash = hash('sha256', $item['e164'].'|'.$item['source_url']);
                $seen[$hash] = true;
                DB::table(self::TABLE_FINDINGS)->insertOrIgnore([
                    'run_id' => $runId,
                    'phone_e164' => $item['e164'],
                    'kind' => $item['kind'], 'provider' => $item['provider'],
                    'source_url' => $item['source_url'],
                    'source_hash' => $hash,
                    'title' => mb_substr($item['title'], 0, 180),
                    'discovered_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp,
                ]);
            }

            $status = $success ? 'completed' : 'unavailable';
            DB::table(self::TABLE_RUNS)->where('id', $runId)->where('status', 'running')
                ->update(['status' => $status,
                    'error_code' => $success ? null : 'sources_unavailable',
                    'source_status' => json_encode($statuses, JSON_THROW_ON_ERROR),
                    'findings_count' => count($seen), 'finished_at' => now('UTC'),
                    'updated_at' => now('UTC')]);

            return ['status' => $status, 'findings' => count($seen)];
        } catch (Throwable $error) {
            report($error);
            $this->markFailed($runId);

            return ['status' => 'failed', 'findings' => 0];
        }
    }

    public function markFailed(int $runId): void
    {
        DB::table(self::TABLE_RUNS)->where('id', $runId)
            ->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error_code' => 'research_failed',
                'finished_at' => now('UTC'), 'updated_at' => now('UTC')]);
    }

    /**
     * @return array{0:list<array{provider:string,e164:string,kind:string,source_url:string,title:string}>,1:bool}
     */
    private function scanSite(string $domain): array
    {
        $fetcher = app()->bound(PublicWhatsappWebsiteFetcher::class)
            ? app(PublicWhatsappWebsiteFetcher::class)
            : app(SafePublicWhatsappWebsiteFetcher::class);
        $extractor = app(PublicWhatsappLinkExtractor::class);
        $home = $fetcher->get($domain, '/');
        if ($home === null) {
            return [[], false];
        }
        $pages = [$home];
        $paths = $extractor->contactPages($home['html'], $domain);
        if ($paths === []) {
            $paths = ['/contato'];
        }
        foreach (array_slice($paths, 0, 2) as $path) {
            $page = $fetcher->get($domain, $path);
            if ($page !== null) {
                $pages[] = $page;
            }
        }
        $found = [];
        foreach ($pages as $page) {
            $source = $this->safeUrl((string) $page['url']);
            if ($source === null) {
                continue;
            }
            foreach ($extractor->extract($page['html']) as $link) {
                $found[] = [
                    'provider' => 'site_oficial', 'e164' => $link['e164'],
                    'kind' => 'explicit_whatsapp_link', 'source_url' => $source,
                    'title' => 'WhatsApp publicado no site da empresa',
                ];
            }
            $html = (string) $page['html'];
            // Telefones em links tel: podem não aparecer no texto visível.
            $telMatches = [];
            preg_match_all('~href\s*=\s*["\']tel:([^"\']+)["\']~i', $html, $telMatches);
            foreach ($telMatches[1] as $tel) {
                $info = BrazilPhoneNumber::inspect(rawurldecode((string) $tel));
                if ($info !== null && $info['e164'] !== null
                    && in_array($info['kind'], ['mobile', 'landline'], true)) {
                    $found[] = [
                        'provider' => 'site_oficial', 'e164' => $info['e164'],
                        'kind' => 'public_phone_not_verified', 'source_url' => $source,
                        'title' => 'Telefone divulgado no link de contato do site',
                    ];
                }
            }
            $html = preg_replace('~<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>~is', ' ', $html) ?? '';
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            foreach ($this->phoneCandidates($text) as $phone) {
                $found[] = [
                    'provider' => 'site_oficial', 'e164' => $phone,
                    'kind' => 'public_phone_not_verified', 'source_url' => $source,
                    'title' => 'Telefone divulgado no site da empresa',
                ];
            }
        }

        return [$found, true];
    }

    /**
     * @return array{0:list<array{provider:string,e164:string,kind:string,source_url:string,title:string}>,1:bool,2:string}
     */
    private function scanTavily(string $name, ?string $domain): array
    {
        $queries = ['"'.mb_substr($name, 0, 85).'" WhatsApp telefone contato'];
        if ($domain !== null) {
            $queries[] = 'site:'.$domain.' WhatsApp telefone atendimento';
        } else {
            $queries[] = '"'.mb_substr($name, 0, 85).'" contato telefone';
        }
        $found = [];
        $ok = false;
        $reason = 'sem_resposta';
        foreach ($queries as $query) {
            try {
                $response = Http::withToken((string) config('whatsapp_public_search.tavily_token'))
                    ->acceptJson()->connectTimeout(3)->timeout(14)->post('https://api.tavily.com/search', [
                        'query' => $query, 'search_depth' => 'basic', 'max_results' => 8,
                        'include_answer' => false, 'include_raw_content' => false,
                        'include_images' => false,
                    ]);
                if (! $response->successful()) {
                    if (! $ok) {
                        $reason = 'http_'.$response->status();
                    }

                    continue;
                }
                $ok = true;
                $reason = 'consultado';
                $results = data_get($response->json(), 'results', []);
                if (! is_array($results)) {
                    continue;
                }
                foreach (array_slice($results, 0, 8) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    array_push($found, ...$this->fromSearchResult($item, $name, $domain, 'tavily'));
                }
            } catch (Throwable) {
                $reason = 'erro_conexao';
                // Falha do provedor não cancela buscas de outras fontes.
            }
        }

        return [$found, $ok, $reason];
    }

    /**
     * @return array{0:list<array{provider:string,e164:string,kind:string,source_url:string,title:string}>,1:bool,2:string}
     */
    private function scanBrave(string $name, ?string $domain): array
    {
        $queries = ['"'.mb_substr($name, 0, 85).'" WhatsApp telefone'];
        if ($domain !== null) {
            $queries[] = 'site:'.$domain.' telefone contato';
        } else {
            $queries[] = '"'.mb_substr($name, 0, 85).'" site contato';
        }
        $found = [];
        $ok = false;
        $reason = 'sem_resposta';
        foreach ($queries as $query) {
            try {
                $response = Http::withHeaders([
                    'X-Subscription-Token' => (string) config('whatsapp_public_search.brave_token'),
                    'Accept' => 'application/json',
                ])->connectTimeout(3)->timeout(12)
                    ->get('https://api.search.brave.com/res/v1/web/search', [
                        'q' => $query, 'country' => 'BR', 'search_lang' => 'pt', 'count' => 8,
                    ]);
                if (! $response->successful()) {
                    if (! $ok) {
                        $reason = 'http_'.$response->status();
                    }

                    continue;
                }
                $ok = true;
                $reason = 'consultado';
                $results = data_get($response->json(), 'web.results', []);
                if (! is_array($results)) {
                    continue;
                }
                foreach (array_slice($results, 0, 8) as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    array_push($found, ...$this->fromSearchResult($item, $name, $domain, 'brave'));
                }
            } catch (Throwable) {
                $reason = 'erro_conexao';
                // Busca externa opcional; não registrar credenciais no erro.
            }
        }

        return [$found, $ok, $reason];
    }

    /**
     * @param  array<string,mixed>  $item
     * @return list<array{provider:string,e164:string,kind:string,source_url:string,title:string}>
     */
    private function fromSearchResult(array $item, string $name, ?string $domain, string $provider): array
    {
        $url = $this->safeUrl((string) ($item['url'] ?? ''));
        if ($url === null) {
            return [];
        }
        $title = mb_substr((string) ($item['title'] ?? 'Resultado público'), 0, 180);
        $description = mb_substr((string) ($item['content'] ?? $item['description'] ?? ''), 0, 2500);
        if (! $this->relevant($name, $domain, $url, $title, $description)) {
            return [];
        }
        $found = [];
        $snippet = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Índice público: não visitar automaticamente URLs externas arbitrárias.
        $linkText = $url.' '.$snippet;
        $matches = [];
        preg_match_all('~https?://(?:wa\.me/\+?[0-9]{10,13}|api\.whatsapp\.com/send\?[^\s<>"\']+)~i', $linkText, $matches);
        foreach (array_unique($matches[0]) as $link) {
            $number = PublicWhatsappLinkExtractor::numberFromLink($link);
            if ($number !== null) {
                $found[] = [
                    'provider' => $provider, 'e164' => $number,
                    'kind' => 'explicit_whatsapp_link', 'source_url' => $url,
                    'title' => $title,
                ];
            }
        }
        // Número público SEM link inequívoco não prova que existe WhatsApp.
        foreach ($this->phoneCandidates($snippet) as $number) {
            $found[] = [
                'provider' => $provider, 'e164' => $number,
                'kind' => 'public_phone_not_verified', 'source_url' => $url,
                'title' => $title,
            ];
        }

        return $found;
    }

    private function relevant(string $name, ?string $domain, string $url, string $title, string $description): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($domain !== null && ($host === $domain || str_ends_with($host, '.'.$domain))) {
            return true;
        }
        $normalized = TextNormalizer::companyName($name) ?? '';
        $words = explode(' ', $normalized);
        $mainWord = strtolower((string) ($words[0]));
        if (strlen($mainWord) < 5 || in_array($mainWord, [
            'empresa', 'empresas', 'industria', 'cooperativa', 'comercio', 'servicos', 'grupo',
        ], true)) {
            return false;
        }
        $haystack = strtolower((string) (TextNormalizer::companyName($title.' '.$description) ?? ''));

        return str_contains($haystack, $mainWord);
    }

    /** @return list<string> */
    private function phoneCandidates(string $text): array
    {
        $numbers = [];
        $text = mb_substr($text, 0, 15000);
        $found = [];
        preg_match_all('/(?<!\d)(?:\+?55[\s().-]*)?\(?([1-9]\d)\)?[\s().-]*([2-9]\d{3,4})[\s().-]*(\d{4})(?!\d)/u', $text, $found, PREG_SET_ORDER);
        foreach ($found as $match) {
            $info = BrazilPhoneNumber::inspect('55'.$match[1].$match[2].$match[3]);
            if ($info !== null && $info['e164'] !== null && in_array($info['kind'], ['mobile', 'landline'], true)) {
                $numbers[$info['e164']] = $info['e164'];
            }
        }

        return array_values($numbers);
    }

    private function safeUrl(string $url): ?string
    {
        if (strlen($url) > 750 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/D', $host)
            || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return $url;
    }
}
