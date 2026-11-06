<?php

namespace App\Services\Contacts;

/**
 * Classificador conservador. Formato plausível NÃO confirma linha ativa nem WhatsApp.
 * Não tenta adivinhar DDD, acrescentar nono dígito ou remover prefixos de operadora.
 */
final class BrazilPhoneNumber
{
    private const DDDS = [
        11, 12, 13, 14, 15, 16, 17, 18, 19,
        21, 22, 24, 27, 28,
        31, 32, 33, 34, 35, 37, 38,
        41, 42, 43, 44, 45, 46, 47, 48, 49,
        51, 53, 54, 55,
        61, 62, 63, 64, 65, 66, 67, 68, 69,
        71, 73, 74, 75, 77, 79,
        81, 82, 83, 84, 85, 86, 87, 88, 89,
        91, 92, 93, 94, 95, 96, 97, 98, 99,
    ];

    /** @return array{key:string,digits:string,e164:?string,kind:string}|null */
    public static function inspect(?string $input): ?array
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $input);
        if ($digits === '') {
            return null;
        }

        $candidate = match (true) {
            strlen($digits) === 10 || strlen($digits) === 11 => '55'.$digits,
            in_array(strlen($digits), [12, 13], true) && str_starts_with($digits, '55') => $digits,
            default => null,
        };

        if ($candidate !== null) {
            $ddd = (int) substr($candidate, 2, 2);
            if (in_array($ddd, self::DDDS, true)) {
                $kind = match (true) {
                    preg_match('/^55\d{2}9\d{8}$/D', $candidate) === 1 => 'mobile',
                    preg_match('/^55\d{2}[2-5]\d{7}$/D', $candidate) === 1 => 'landline',
                    default => 'review',
                };
                if ($kind !== 'review') {
                    return ['key' => $candidate, 'digits' => $digits, 'e164' => $candidate, 'kind' => $kind];
                }
            }
        }

        // Identificador opaco: registros incompletos não são unidos a números válidos.
        return [
            'key' => 'raw:'.hash('sha256', $digits),
            'digits' => substr($digits, 0, 80),
            'e164' => null,
            'kind' => 'review',
        ];
    }

    public static function display(?string $e164, string $digits): string
    {
        if ($e164 !== null && preg_match('/^55(\d{2})(\d{8,9})$/D', $e164, $m)) {
            $subscriber = $m[2];
            $break = strlen($subscriber) === 9 ? 5 : 4;

            return '+55 ('.$m[1].') '.substr($subscriber, 0, $break).'-'.substr($subscriber, $break);
        }

        return $digits;
    }
}
