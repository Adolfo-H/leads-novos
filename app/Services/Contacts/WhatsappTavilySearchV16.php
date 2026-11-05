<?php

namespace App\Services\Contacts;

use Illuminate\Support\Facades\Http;

/** Pesquisa web indexada via Tavily (plano gratuito opcional). Não raspa LinkedIn/Maps. */
final class WhatsappTavilySearchV16
{
    /** @return array{success:bool,items:list<array{provider:string,e164:string,kind:string,url:string,title:string}>} */
    public function search(string $name, string $domain): array
    {
        $token = trim((string) config('whatsapp_public_search.tavily_token', ''));
        if ($token === '') {
            return ['success' => false, 'items' => []];
        }
        $queries = [
            '"'.mb_substr($name, 0, 90).'" WhatsApp atendimento telefone',
            '"'.$domain.'" WhatsApp wa.me',
        ];
        $items = [];
        $successful = false;
        foreach ($queries as $query) {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(4)->timeout(14)
                ->post('https://api.tavily.com/search', [
                    'query' => $query, 'search_depth' => 'basic', 'topic' => 'general',
                    'max_results' => 6, 'include_answer' => false, 'include_raw_content' => false,
                    'include_images' => false,
                ]);
            if (! $response->successful()) {
                continue;
            }
            $successful = true;
            foreach ((array) data_get($response->json(), 'results', []) as $result) {
                $source = $this->safeUrl((string) ($result['url'] ?? ''));
                if ($source === null) {
                    continue;
                }
                $text = html_entity_decode((string) ($result['content'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $links = [$source];
                if (preg_match_all('~https?://(?:wa\.me/[+0-9]+|api\.whatsapp\.com/send\?[^\s<>"\']+)~i', $text, $found)) {
                    array_push($links, ...$found[0]);
                }
                foreach (array_unique($links) as $link) {
                    $number = PublicWhatsappLinkExtractor::numberFromLink($link);
                    if ($number === null) {
                        continue;
                    }
                    $items[$number.'|'.$source] = [
                        'provider' => 'tavily_index', 'e164' => $number,
                        'kind' => 'explicit_whatsapp_link', 'url' => $source,
                        'title' => mb_substr((string) ($result['title'] ?? 'Busca pública'), 0, 180),
                    ];
                }
                // Um telefone em um snippet que menciona WhatsApp não é conta confirmada.
                // Não criaremos associação automática sem URL inequívoca.
            }
        }

        return ['success' => $successful, 'items' => array_values($items)];
    }

    private function safeUrl(string $url): ?string
    {
        if (strlen($url) > 750 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/D', $host)
            || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $url;
    }
}
