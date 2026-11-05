<?php

namespace App\Services\Contacts;

/** Extrai links explícitos publicados em HTML; nunca prova conta ativa. */
final class PublicWhatsappLinkExtractor
{
    /** @return list<array{e164:string,wa_url:string,label:string}> */
    public function extract(string $html): array
    {
        $found = [];
        foreach ($this->anchors($html) as $anchor) {
            $e164 = self::numberFromLink($anchor['href']);
            if ($e164 === null) {
                continue;
            }
            $found[$e164] = [
                'e164' => $e164,
                'wa_url' => 'https://wa.me/'.$e164,
                'label' => mb_substr($anchor['text'], 0, 100),
            ];
        }

        return array_values($found);
    }

    /** @return list<string> Paths within the original company domain. */
    public function contactPages(string $html, string $host): array
    {
        $links = [];
        foreach ($this->anchors($html) as $anchor) {
            $href = $anchor['href'];
            if (! preg_match('/(contat|contact|fale|atendimento)/ui', mb_strtolower($anchor['text']).' '.$href)) {
                continue;
            }
            if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
                $parts = parse_url($href);
                $path = $parts['path'] ?? '';
            } elseif (str_starts_with(strtolower($href), 'https://')) {
                $parts = parse_url($href);
                $other = strtolower((string) ($parts['host'] ?? ''));
                if (! in_array($other, [$host, 'www.'.$host], true)) {
                    continue;
                }
                $path = $parts['path'] ?? '';
            } else {
                continue;
            }
            if ($path === '/' || strlen($path) > 120 || str_contains($path, '..')
                || ! preg_match('~^/[a-zA-Z0-9/_%-]+$~D', $path)) {
                continue;
            }
            $links[$path] = $path;
            if (count($links) >= 2) {
                break;
            }
        }

        return array_values($links);
    }

    public static function numberFromLink(string $link): ?string
    {
        if ($link === '' || strlen($link) > 1200) {
            return null;
        }
        $parts = parse_url(trim($link));
        if ($parts === false) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $candidate = null;
        if (in_array($scheme, ['http', 'https'], true) && in_array($host, ['wa.me', 'www.wa.me'], true)) {
            $candidate = trim((string) ($parts['path'] ?? ''), '/');
        } elseif ((in_array($scheme, ['http', 'https'], true) && in_array($host, [
            'api.whatsapp.com', 'web.whatsapp.com', 'www.whatsapp.com',
        ], true)) || $scheme === 'whatsapp') {
            if ($scheme !== 'whatsapp' && ! str_starts_with((string) ($parts['path'] ?? ''), '/send')) {
                return null;
            }
            if ($scheme === 'whatsapp' && $host !== 'send') {
                return null;
            }
            parse_str((string) ($parts['query'] ?? ''), $query);
            $candidate = isset($query['phone']) && is_string($query['phone']) ? $query['phone'] : null;
        }
        if (! is_string($candidate) || ! preg_match('/^\+?\d{10,13}$/D', $candidate)) {
            return null;
        }
        $info = BrazilPhoneNumber::inspect($candidate);

        return $info !== null && in_array($info['kind'], ['mobile', 'landline'], true)
            ? $info['e164'] : null;
    }

    /**
     * Extrai apenas atributos href de tags <a> (não executa scripts).
     * Usamos parser leve porque php-xml/DOM pode não estar instalado no Sail.
     *
     * @return list<array{href:string,text:string}>
     */
    private function anchors(string $html): array
    {
        $html = mb_substr($html, 0, 450000);
        $items = [];
        if (preg_match_all('~<a\b([^>]*)>(.*?)</a\s*>~isu', $html, $matches, PREG_SET_ORDER) === false) {
            return [];
        }
        foreach ($matches as $parts) {
            if (preg_match('~\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~iu', $parts[1], $href) !== 1) {
                continue;
            }
            $doubleQuoted = $href[1];
            $singleQuoted = $href[2] ?? '';
            $unquoted = $href[3] ?? '';
            $rawHref = $doubleQuoted !== '' ? $doubleQuoted : ($singleQuoted !== '' ? $singleQuoted : $unquoted);
            $url = html_entity_decode($rawHref, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($url === '' || strlen($url) > 1200) {
                continue;
            }
            $text = html_entity_decode((string) strip_tags($parts[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $items[] = ['href' => $url, 'text' => trim(preg_replace('/\s+/u', ' ', $text) ?? '')];
        }

        return $items;
    }
}
