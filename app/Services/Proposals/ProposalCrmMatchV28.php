<?php

namespace App\Services\Proposals;

/** Deterministic, offline matching. No stage-based preference or fuzzy matching. */
final class ProposalCrmMatchV28
{
    public const STAGES = ['contractsent', '14249606', '14249607', 'closedwon'];

    public const LABELS = ['ACEITE DA PROPOSTA', 'CONTRATO ASSINADO', 'INICIO DO TESTE', 'NEGOCIO FECHADO'];

    public static function normalize(mixed $text): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $text);

        return trim((string) preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($value === false ? '' : $value)));
    }

    public static function company(mixed $name): string
    {
        $key = self::normalize($name);
        $key = trim((string) preg_replace('/\s+(LTDA|LIMITADA|S A|SA|EIRELI|ME|EPP)$/', '', $key));

        return strlen($key) >= 4 && ! in_array($key, ['EMPRESA', 'CLIENTE', 'MODELO', 'TESTE', 'PROPOSTA', 'PROPOSTAS ANTIGAS'], true) ? $key : '';
    }

    /** @return list<string> */
    public static function services(mixed $name): array
    {
        $name = self::normalize($name);
        $patterns = ['LICENCIAMENTO' => '/\bLIC(?:ENCIAMENTO|ENC)?\b/', 'ACOMPANHAMENTO' => '/\b(?:ACOMP(?:ANHAMENTO)?|ACOMPANHAR)\b/', 'RETROATIVAS' => '/\b(?:RETROATIV[AO]S?|RETRO)\b/', 'DFE' => '/\b(?:DFE|DF E|D F E)\b/', 'NFSE' => '/\b(?:NFSE|NFS E|N F S E|NFS)\b/'];
        $result = [];
        foreach ($patterns as $service => $pattern) {
            if (preg_match($pattern, $name) === 1) {
                $result[] = $service;
            }
        }
        sort($result);

        return $result;
    }

    public static function accepted(?string $id, ?string $label, ?string $pipeline = null): bool
    {
        if ($pipeline !== null && $pipeline !== '' && $pipeline !== 'default') {
            return false;
        }

        return $id !== null && $id !== '' ? in_array($id, self::STAGES, true) : in_array(self::normalize($label), self::LABELS, true);
    }

    /** @return list<string> */
    public static function references(mixed $text): array
    {
        $s = self::normalize($text);
        preg_match_all('/\b(?:EC|PROPOSTA(?: COMERCIAL)?)\s+(20\d{2})\s+(\d{1,9})\b/', $s, $matches, PREG_SET_ORDER);
        $refs = [];
        foreach ($matches as $m) {
            $refs[] = $m[1].'/'.(int) $m[2];
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  list<string>  $services
     * @param  array<string, mixed>  $deal
     */
    public static function criterion(array $services, string $ref, ?string $issued, array $deal): ?string
    {
        $found = self::services($deal['name'] ?? '');
        sort($services);
        $refs = self::references($deal['name'] ?? '');
        if ($refs !== []) {
            return $ref !== '' && in_array($ref, $refs, true) && count($refs) === 1 && ($found === [] || $found === $services) ? 'exact_code' : null;
        }
        if ($services === [] || $found !== $services || $issued === null || ! preg_match('/^20\d{2}-\d{2}-\d{2}$/D', $issued)) {
            return null;
        }
        $created = substr((string) ($deal['hubspot_created_at'] ?? ''), 0, 10);
        $closed = substr((string) ($deal['closed_at'] ?? ''), 0, 10);
        // Exact reference can be explicit despite a delayed CRM entry. Service-only requires chronology.
        if ($created === '' || $created > date('Y-m-d', strtotime($issued.' +365 days') ?: 0) || $created < date('Y-m-d', strtotime($issued.' -365 days') ?: 0) || ($closed !== '' && $closed < $issued)) {
            return null;
        }
        preg_match_all('/\b20\d{2}\b/', self::normalize($deal['name'] ?? ''), $years);
        if ($years[0] !== [] && ! in_array(substr($issued, 0, 4), $years[0], true)) {
            return null;
        }

        return 'unique_company_service';
    }
}
