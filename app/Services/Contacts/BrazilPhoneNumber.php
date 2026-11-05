<?php

namespace App\Services\Contacts;

/**
 * Normalização CONSERVADORA, exclusivamente local.
 * Nenhum formato plausível confirma existência da linha ou conta WhatsApp.
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

    // Códigos de prestadora que conseguimos reconhecer sem adivinhar o DDD.
    // Uma entrada de prefixo não reconhecido continua em 'review'.
    private const CSP_KNOWN = [12, 14, 15, 17, 21, 23, 25, 31, 32, 41, 43, 65];

    /** @return array{key:string,digits:string,e164:?string,kind:string,normalization:?string}|null */
    public static function inspect(?string $input): ?array
    {
        // A apresentação (espaços, hífens, parênteses) não interfere na classificação.
        $digits = (string) preg_replace('/\D+/', '', (string) $input);
        if ($digits === '') {
            return null;
        }

        $variants = [];
        $len = strlen($digits);

        if ($len === 10 || $len === 11) {
            $variants[] = ['55'.$digits, null];
        }
        if (in_array($len, [12, 13], true) && str_starts_with($digits, '55')) {
            $variants[] = [$digits, null];
        }
        // 00 + 55 + DDD + telefone: prefixo de chamada internacional inequívoco.
        if (str_starts_with($digits, '0055') && in_array($len, [14, 15], true)) {
            $variants[] = [substr($digits, 2), 'prefixo_internacional_00'];
        }
        // 0 + DDD + telefone: zero de chamada interurbana, com DDD presente.
        if (str_starts_with($digits, '0') && in_array($len, [11, 12], true)) {
            $variants[] = ['55'.substr($digits, 1), 'zero_interurbano'];
        }
        // 0 + CSP + DDD + telefone: só para códigos de prestadora reconhecidos.
        if (str_starts_with($digits, '0') && in_array($len, [13, 14], true)
            && in_array((int) substr($digits, 1, 2), self::CSP_KNOWN, true)) {
            $variants[] = ['55'.substr($digits, 3), 'prefixo_operadora'];
        }

        foreach ($variants as [$candidate, $normalization]) {
            $ddd = (int) substr($candidate, 2, 2);
            if (! in_array($ddd, self::DDDS, true)) {
                continue;
            }
            $kind = match (true) {
                preg_match('/^55\d{2}9\d{8}$/D', $candidate) === 1 => 'mobile',
                preg_match('/^55\d{2}[2-5]\d{7}$/D', $candidate) === 1 => 'landline',
                default => 'review',
            };
            if ($kind !== 'review') {
                return [
                    'key' => $candidate,
                    'digits' => $digits,
                    'e164' => $candidate,
                    'kind' => $kind,
                    'normalization' => $normalization,
                ];
            }
        }

        // Nunca anexar DDD por geolocalização, adicionar nono dígito de forma
        // silenciosa ou dividir números concatenados: essas ações são ambíguas.
        return [
            'key' => 'raw:'.hash('sha256', $digits),
            'digits' => substr($digits, 0, 80),
            'e164' => null,
            'kind' => 'review',
            'normalization' => null,
        ];
    }

    /**
     * Dica visual para um número de revisão; nunca altera nem verifica o telefone.
     *
     * @return array{title:string,message:string,candidate:?string}|null
     */
    public static function hint(?string $input): ?array
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $input);
        $info = self::inspect($input);
        if ($digits === '' || ($info !== null && $info['kind'] !== 'review')) {
            return null;
        }

        // DDD + 8 dígitos que se parecem com número celular anterior ao nono dígito.
        $national = str_starts_with($digits, '55') && strlen($digits) === 12
            ? substr($digits, 2) : $digits;
        if (strlen($national) === 10
            && in_array((int) substr($national, 0, 2), self::DDDS, true)
            && preg_match('/^[6-9]\d{7}$/D', substr($national, 2)) === 1) {
            $candidate = '55'.substr($national, 0, 2).'9'.substr($national, 2);

            return [
                'title' => 'Possível celular antigo, sem o nono dígito',
                'message' => 'Confira o número atualizado com o contato ou na origem antes de incluir o 9. Não foi corrigido automaticamente.',
                'candidate' => self::display($candidate, $national),
            ];
        }
        if (in_array(strlen($digits), [8, 9], true)) {
            return [
                'title' => 'Número sem DDD',
                'message' => 'É necessário obter o DDD na fonte. O sistema não infere o DDD pela cidade ou empresa.',
                'candidate' => null,
            ];
        }
        if (strlen($digits) > 15) {
            return [
                'title' => 'Número longo ou vários telefones juntos',
                'message' => 'Confira se há dois números no mesmo campo. A divisão exige revisão da fonte.',
                'candidate' => null,
            ];
        }
        if (preg_match('/^0(?:800|300|500|900)/D', $digits) === 1) {
            return [
                'title' => 'Número de serviço',
                'message' => '0800, 0300 e similares não são tratados como celular ou fixo individual.',
                'candidate' => null,
            ];
        }

        return [
            'title' => 'Formato precisa de conferência',
            'message' => 'Confira DDD, quantidade de dígitos e prefixo. A formatação só é aplicada automaticamente quando não há ambiguidade.',
            'candidate' => null,
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
