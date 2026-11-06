<?php

namespace App\Services\Contacts;

use App\Jobs\ResearchWhatsappExternalCompanyV14;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Pesquisa indexada pública, opcional. NÃO abre Google Maps/LinkedIn para raspar dados;
 * não valida conta WhatsApp e NÃO associa um telefone encontrado automaticamente.
 */
final class WhatsappExternalCompanyDiscoveryV14
{
    public function configured(): bool
    {
        return filled(config('whatsapp_public_search.brave_token'));
    }

    public function queue(User $actor, int $companyId): int
    {
        abort_unless($actor->isCommercialManager(), 403);
        if (! $this->configured()) {
            throw ValidationException::withMessages(['public_site' =>
                'Pesquisa externa requer uma chave Brave Search no .env. A busca no site da empresa continua disponível sem chave.']);
        }
        $company = DB::table('hubspot_companies')->where('id', $companyId)
            ->whereNotNull('domain')->first(['id','name','domain']);
        abort_unless($company !== null && SafePublicWhatsappWebsiteFetcher::domain($company->domain) !== null, 404);
        $id = DB::transaction(function () use ($actor, $companyId): int {
            $previous = DB::table('whatsapp_public_external_company_runs')->where('hubspot_company_id', $companyId)
                ->orderByDesc('id')->lockForUpdate()->first();
            if ($previous && in_array($previous->status, ['queued','running'], true)) {
                throw ValidationException::withMessages(['public_site'=>'Já existe uma pesquisa externa em andamento nesta empresa.']);
            }
            if ($previous && $previous->created_at && \Carbon\Carbon::parse($previous->created_at)->gt(now()->subHours(6))) {
                throw ValidationException::withMessages(['public_site'=>'Essa empresa já foi pesquisada recentemente. Aguarde 6 horas para nova consulta externa.']);
            }
            return (int) DB::table('whatsapp_public_external_company_runs')->insertGetId([
                'hubspot_company_id'=>$companyId, 'created_by'=>$actor->id, 'status'=>'queued',
                'created_at'=>now('UTC'), 'updated_at'=>now('UTC'),
            ]);
        });
        ResearchWhatsappExternalCompanyV14::dispatch($id)->onQueue('default');
        return $id;
    }

    /** @return array{findings:int,status:string} */
    public function execute(int $runId): array
    {
        $run = DB::table('whatsapp_public_external_company_runs')->where('id', $runId)->first();
        if ($run === null || $run->status !== 'queued') {
            return ['findings'=>0, 'status'=>'skipped'];
        }
        DB::table('whatsapp_public_external_company_runs')->where('id', $runId)->where('status', 'queued')
            ->update(['status'=>'running','updated_at'=>now('UTC')]);
        $company = DB::table('hubspot_companies')->where('id', $run->hubspot_company_id)->first();
        if ($company === null) {
            return ['findings'=>0, 'status'=>'skipped'];
        }
        $pending = [];
        $success = false;
        try {
            $name = mb_substr(trim((string) ($company->name ?: $company->domain)), 0, 110);
            $domain = SafePublicWhatsappWebsiteFetcher::domain($company->domain);
            if ($domain === null) {
                throw new \RuntimeException('invalid_company_domain');
            }
            if (filled(config('whatsapp_public_search.brave_token'))) {
                // Busca pública indexada. URLs do LinkedIn só são links de referência;
                // nenhuma página do LinkedIn será acessada diretamente.
                foreach (['"'.$name.'" WhatsApp', '"'.$name.'" site:linkedin.com/company WhatsApp', '"'.$name.'" site:google.com/maps WhatsApp'] as $query) {
                    $response = Http::withHeaders([
                        'X-Subscription-Token'=>(string) config('whatsapp_public_search.brave_token'),
                        'Accept'=>'application/json',
                    ])->connectTimeout(4)->timeout(12)->get('https://api.search.brave.com/res/v1/web/search', [
                        'q'=>$query, 'country'=>'BR', 'search_lang'=>'pt', 'count'=>8,
                    ]);
                    if (! $response->successful()) {
                        continue;
                    }
                    $success = true;
                    foreach ((array) data_get($response->json(), 'web.results', []) as $result) {
                        $sourceUrl = $this->safePublicUrl((string) ($result['url'] ?? ''));
                        if ($sourceUrl === null) {
                            continue;
                        }
                        // Apenas um link explícito ao WhatsApp é candidato a canal publicado.
                        $candidates = array_filter([(string) ($result['url'] ?? '')]);
                        $description = (string) ($result['description'] ?? '');
                        if (preg_match_all('~https?://(?:wa\.me/[+0-9]+|api\.whatsapp\.com/send\?[^\s<>"\']+)~i', $description, $links)) {
                            $candidates = array_merge($candidates, $links[0]);
                        }
                        foreach ($candidates as $candidate) {
                            $number = PublicWhatsappLinkExtractor::numberFromLink(html_entity_decode($candidate, ENT_QUOTES));
                            if ($number !== null) {
                                $pending[] = ['provider'=>'brave_index','e164'=>$number,
                                    'kind'=>'explicit_whatsapp_link', 'url'=>$sourceUrl,
                                    'title'=>(string) ($result['title'] ?? 'Índice público')];
                            }
                        }
                    }
                }
            }
            $now = now('UTC');
            $seen = [];
            foreach ($pending as $entry) {
                $hash = hash('sha256', $entry['provider'].'|'.$entry['e164'].'|'.$entry['url']);
                if (isset($seen[$hash])) {
                    continue;
                }
                $seen[$hash] = true;
                DB::table('whatsapp_public_external_company_findings')->upsert([[
                    'hubspot_company_id'=>(int) $company->id,
                    'provider'=>$entry['provider'], 'phone_e164'=>$entry['e164'],
                    'finding_type'=>$entry['kind'], 'source_url'=>$entry['url'],
                    'source_hash'=>$hash, 'title'=>mb_substr($entry['title'], 0, 180),
                    'review_status'=>'pending', 'discovered_at'=>$now,
                    'created_at'=>$now, 'updated_at'=>$now,
                ]], ['hubspot_company_id','source_hash'], ['discovered_at','updated_at']);
            }
            $status = $success ? 'completed' : 'unavailable';
            DB::table('whatsapp_public_external_company_runs')->where('id',$runId)->update([
                'status'=>$status,'findings_count'=>count($seen), 'error_code'=>$success ? null : 'provider_unavailable',
                'finished_at'=>$now,'updated_at'=>$now,
            ]);
            return ['findings'=>count($seen), 'status'=>$status];
        } catch (\Throwable $e) {
            report($e);
            DB::table('whatsapp_public_external_company_runs')->where('id',$runId)->update([
                'status'=>'failed','error_code'=>'external_query_failed', 'finished_at'=>now('UTC'), 'updated_at'=>now('UTC'),
            ]);
            return ['findings'=>0,'status'=>'failed'];
        }
    }

    public function accept(User $actor, int $companyId, int $findingId): void
    {
        abort_unless($actor->isCommercialManager(),403);
        $item = DB::table('whatsapp_public_external_company_findings')
            ->where('id',$findingId)->where('hubspot_company_id',$companyId)->first();
        abort_unless($item !== null && $item->review_status === 'pending', 404);
        if ($item->finding_type !== 'explicit_whatsapp_link') {
            throw ValidationException::withMessages(['public_site'=>
                'Um número encontrado no Google Maps não comprova WhatsApp. Confirme manualmente antes de cadastrar como canal WhatsApp.']);
        }
        $company = DB::table('hubspot_companies')->where('id',$companyId)->first(['domain','name']);
        $domain = SafePublicWhatsappWebsiteFetcher::domain($company?->domain);
        abort_unless($domain !== null, 404);
        // A empresa foi selecionada por um gestor; fonte externa passa ao cadastro
        // APÓS aprovação explícita e mantém URL para auditoria.
        DB::transaction(function () use ($item,$companyId,$domain,$company): void {
            DB::table('whatsapp_public_site_evidences')->insertOrIgnore([
                'domain'=>$domain, 'e164'=>$item->phone_e164,
                'source_url'=>$item->source_url,'wa_url'=>'https://wa.me/'.$item->phone_e164,
                'link_label'=>'Fonte externa revisada pelo gestor',
                'company_label'=>mb_substr((string) $company->name,0,180),
                'first_seen_at'=>now('UTC'),'last_seen_at'=>now('UTC'),
                'created_at'=>now('UTC'),'updated_at'=>now('UTC'),
            ]);
            DB::table('whatsapp_public_external_company_findings')->where('id',$item->id)
                ->update(['review_status'=>'accepted','updated_at'=>now('UTC')]);
        });
        app(WhatsappPublicCompanyChannels::class)->syncDomain($domain);
    }

    /** @return \Illuminate\Support\Collection<int,object> */
    public function findings(int $companyId): \Illuminate\Support\Collection
    {
        return DB::table('whatsapp_public_external_company_findings')
            ->where('hubspot_company_id',$companyId)->latest('id')->limit(30)->get();
    }
    public function latestRun(int $companyId): ?object
    {
        return DB::table('whatsapp_public_external_company_runs')
            ->where('hubspot_company_id',$companyId)->latest('id')->first();
    }

    private function safePublicUrl(string $url): ?string
    {
        if (strlen($url) > 420 || ! filter_var($url,FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower((string)($parts['scheme']??'')) !== 'https'
            || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower((string)($parts['host']??''));
        if (!preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/D', $host)
            || filter_var($host,FILTER_VALIDATE_IP)) {
            return null;
        }
        return $url;
    }
}
