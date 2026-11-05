<?php

namespace App\Services\Contacts;

/**
 * Buscador público com proteção SSRF: só domínio, HTTPS, DNS público,
 * IP fixado no cURL, sem redirects automáticos e com limite de bytes.
 */
final class SafePublicWhatsappWebsiteFetcher implements PublicWhatsappWebsiteFetcher
{
    private const MAX_BYTES = 450000;

    public static function domain(?string $raw): ?string
    {
        $raw = strtolower(trim((string) $raw));
        if ($raw === '' || strlen($raw) > 350 || preg_match('/[\s\x00-\x1f\\\\@]/', $raw)) {
            return null;
        }
        $url = str_contains($raw, '://') ? $raw : 'https://'.$raw;
        $parts = parse_url($url);
        if ($parts === false || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if (strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP)
            || preg_match('/^(?=.{4,253}$)[a-z0-9-]+(?:\.[a-z0-9-]+)+$/D', $host) !== 1
            || str_contains($host, '..')) {
            return null;
        }
        foreach (explode('.', $host) as $label) {
            if (str_starts_with($label, '-') || str_ends_with($label, '-') || strlen($label) > 63) {
                return null;
            }
        }
        $tld = substr($host, strrpos($host, '.') + 1);
        if (in_array($tld, ['local', 'localhost', 'internal', 'test', 'invalid', 'example', 'onion', 'lan'], true)
            || in_array($host, ['facebook.com', 'instagram.com', 'linkedin.com', 'whatsapp.com', 'wa.me', 'google.com', 'gmail.com'], true)) {
            return null;
        }

        return $host;
    }

    public function get(string $registeredHost, string $path = '/'): ?array
    {
        $host = self::domain($registeredHost);
        if ($host === null || ! preg_match('~^/[a-zA-Z0-9/_%-]{0,150}$~D', $path)
            || str_contains($path, '..') || ! function_exists('curl_init')) {
            return null;
        }

        $nextHost = $host;
        $nextPath = $path;
        for ($hop = 0; $hop <= 2; $hop++) {
            $result = $this->request($nextHost, $nextPath);
            if ($result === null) {
                return null;
            }
            if ($result['status'] >= 200 && $result['status'] < 300) {
                return str_contains(strtolower($result['content_type']), 'text/html')
                    ? ['url' => 'https://'.$nextHost.$nextPath, 'html' => $result['body']]
                    : null;
            }
            if (! in_array($result['status'], [301, 302, 307, 308], true)) {
                return null;
            }
            $location = trim($result['location']);
            if (str_starts_with($location, '/')) {
                if (str_starts_with($location, '//')) {
                    return null;
                }
                $parts = parse_url($location);
                $candidateHost = $nextHost;
            } else {
                $parts = parse_url($location);
                if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
                    return null;
                }
                $candidateHost = strtolower((string) ($parts['host'] ?? ''));
            }
            if (! in_array($candidateHost, [$host, 'www.'.$host], true)
                || isset($parts['port']) || isset($parts['user'])) {
                return null;
            }
            $candidatePath = (string) ($parts['path'] ?? '/');
            if (! preg_match('~^/[a-zA-Z0-9/_%-]{0,150}$~D', $candidatePath) || str_contains($candidatePath, '..')) {
                return null;
            }
            $nextHost = $candidateHost;
            $nextPath = $candidatePath;
        }

        return null;
    }

    /** @return array{status:int,body:string,content_type:string,location:string}|null */
    private function request(string $host, string $path): ?array
    {
        // Não confiamos no DNS do site: filtramos os IPs E fixamos o endereço na conexão.
        $ips = gethostbynamel($host);
        if (! is_array($ips) || $ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return null;
            }
        }
        $body = '';
        $location = '';
        $type = '';
        $curl = curl_init('https://'.$host.$path);
        if ($curl === false) {
            return null;
        }
        try {
            curl_setopt_array($curl, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 9,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPGET => true,
                CURLOPT_USERAGENT => 'ExportControl-Prospector-PublicLinks/1.0',
                CURLOPT_RESOLVE => ["{$host}:443:{$ips[0]}"],
                CURLOPT_HTTPHEADER => ['Accept: text/html', 'Accept-Encoding: identity'],
                CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$location, &$type): int {
                    if (stripos($header, 'Location:') === 0) {
                        $location = trim(substr($header, 9));
                    } elseif (stripos($header, 'Content-Type:') === 0) {
                        $type = trim(substr($header, 13));
                    }

                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        return 0; // cURL cancela o download, conteúdo desconsiderado.
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($curl);
            if ($ok === false) {
                return null;
            }

            return [
                'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                'body' => $body,
                'content_type' => $type,
                'location' => $location,
            ];
        } finally {
            curl_close($curl);
        }
    }
}
