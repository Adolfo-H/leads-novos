<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Establishment;
use App\Support\TextNormalizer;

final class ExportResearchEntityMatcher
{
    /**
     * Termos que não identificam uma empresa
     * sozinhos.
     *
     * @var list<string>
     */
    private const GENERIC_TOKENS = [
        'SA',
        'S',
        'A',
        'LTDA',
        'EIRELI',
        'ME',
        'EPP',
        'SOCIEDADE',
        'ANONIMA',

        'DE',
        'DA',
        'DO',
        'DAS',
        'DOS',
        'E',

        'AGRO',
        'AGRICOLA',
        'AGRICOLAS',
        'AGROPECUARIA',
        'AGROPECUARIAS',
        'INDUSTRIA',
        'INDUSTRIAS',
        'COMERCIO',
        'COMERCIAL',
        'COMPANHIA',
        'COOPERATIVA',
        'EMPRESA',
        'EMPREENDIMENTOS',
        'PARTICIPACOES',
        'HOLDING',
        'PRODUTOS',
        'ALIMENTICIOS',
    ];

    /**
     * @var list<string>
     */
    private const DOMAIN_NOISE = [
        'www',
        'com',
        'br',
        'net',
        'org',
        'gov',
        'edu',
        'io',
        'co',

        'gmail',
        'hotmail',
        'outlook',
        'yahoo',
        'icloud',
        'terra',
        'uol',
    ];

    public function matches(
        Company $company,
        ?string $title,
        string $content,
        ?string $url = null,
    ): bool {
        $text =
            $this->normalize(
                trim(
                    ($title ?? '')
                    .' '
                    .$content
                )
            );

        if ($text === '') {
            return false;
        }

        /*
         * Primeiro tentamos a razão social
         * praticamente inteira.
         *
         * "AGRO CUMARU S.A."
         * vira
         * "AGRO CUMARU".
         */
        $companyName =
            $this->baseCompanyName(
                $company->corporate_name
            );

        if (
            $companyName !== ''
            && mb_strlen($companyName) >= 5
            && str_contains(
                $text,
                $companyName
            )
        ) {
            return true;
        }

        /*
         * CNPJ é a identificação mais forte.
         */
        $rawText =
            ($title ?? '')
            .' '
            .$content
            .' '
            .($url ?? '');

        $digits =
            preg_replace(
                '/\D+/',
                '',
                $rawText
            )
            ?? '';

        $root =
            preg_replace(
                '/\D+/',
                '',
                (string)
                    $company->cnpj_root
            )
            ?? '';

        if (
            strlen($root) === 8
            && str_contains(
                $digits,
                $root
            )
        ) {
            return true;
        }

        $matrixRelation =
            $company->relationLoaded(
                'matrix'
            )
                ? $company->getRelation(
                    'matrix'
                )
                : null;

        $matrix =
            $matrixRelation instanceof Establishment
                ? $matrixRelation
                : null;

        $matrixCnpj =
            $matrix === null
                ? ''
                : (
                    preg_replace(
                        '/\D+/',
                        '',
                        (string) $matrix->cnpj
                    )
                    ?? ''
                );

        if (
            strlen($matrixCnpj) === 14
            && str_contains(
                $digits,
                $matrixCnpj
            )
        ) {
            return true;
        }

        /*
         * Nome fantasia completo também
         * pode identificar a empresa.
         */
        $fantasy =
            $this->normalize(
                $matrix?->fantasy_name
            );

        if (
            $fantasy !== ''
            && mb_strlen($fantasy) >= 6
            && str_contains(
                $text,
                $fantasy
            )
        ) {
            $fantasyTokens =
                $this->identityTokens(
                    $fantasy
                );

            /*
             * Nome fantasia com duas ou mais
             * palavras identificadoras é forte
             * o bastante por si só.
             */
            if (
                count(
                    $fantasyTokens
                ) >= 2
            ) {
                return true;
            }

            /*
             * Nome fantasia de uma única palavra,
             * como "ITAIPU", não pode validar
             * qualquer página que apenas cite
             * essa palavra.
             *
             * Nesse caso exigimos que o domínio
             * da própria fonte também carregue
             * essa identidade.
             *
             * Ex.:
             * RAIZEN + raizen.com.br => aceita
             * ITAIPU + bndes.gov.br => rejeita
             */
            if (
                count(
                    $fantasyTokens
                ) === 1
                && $this->urlMatchesToken(
                    $url,
                    $fantasyTokens[0]
                )
            ) {
                return true;
            }
        }

        /*
         * Site corporativo.
         *
         * Ex.:
         * email @raizen.com.br
         * fonte www.raizen.com.br
         */
        if (
            $this->matchesCorporateDomain(
                $matrix?->email,
                $url
            )
        ) {
            return true;
        }

        /*
         * Último critério:
         *
         * exigimos pelo menos dois termos
         * realmente identificadores.
         *
         * Isso impede:
         *
         * ITAIPU EMPREENDIMENTOS AGRICOLAS
         * casar somente com "Itaipu".
         *
         * AGRO CUMARU
         * casar somente com "agro".
         */
        $tokens =
            $this->identityTokens(
                $companyName
            );

        if (count($tokens) < 2) {
            return false;
        }

        $matched = 0;

        foreach ($tokens as $token) {
            if (
                $this->containsToken(
                    $text,
                    $token
                )
            ) {
                $matched++;
            }
        }

        return $matched >= 2;
    }

    private function baseCompanyName(
        ?string $value
    ): string {
        $normalized =
            $this->normalize(
                $value
            );

        if ($normalized === '') {
            return '';
        }

        $tokens =
            preg_split(
                '/\s+/',
                $normalized
            )
            ?: [];

        /*
         * Retira somente sufixos jurídicos.
         *
         * Não removemos AGRO, AGRICOLA etc
         * daqui porque a frase inteira ainda
         * é excelente para identificar a
         * empresa.
         */
        while ($tokens !== []) {
            $last =
                end($tokens);

            if (
                ! in_array(
                    $last,
                    [
                        'SA',
                        'S',
                        'A',
                        'LTDA',
                        'EIRELI',
                        'ME',
                        'EPP',
                    ],
                    true
                )
            ) {
                break;
            }

            array_pop(
                $tokens
            );
        }

        return implode(
            ' ',
            $tokens
        );
    }

    /**
     * @return list<string>
     */
    private function identityTokens(
        string $value
    ): array {
        $tokens =
            preg_split(
                '/\s+/',
                $value
            )
            ?: [];

        $filtered = [];

        foreach ($tokens as $token) {
            if (
                mb_strlen($token) < 4
                || in_array(
                    $token,
                    self::GENERIC_TOKENS,
                    true
                )
            ) {
                continue;
            }

            $filtered[] =
                $token;
        }

        return array_values(
            array_unique(
                $filtered
            )
        );
    }

    private function containsToken(
        string $text,
        string $token
    ): bool {
        return str_contains(
            ' '.$text.' ',
            ' '.$token.' '
        );
    }

    private function urlMatchesToken(
        ?string $url,
        string $token,
    ): bool {
        if (
            ! is_string($url)
            || trim($url) === ''
        ) {
            return false;
        }

        $host =
            parse_url(
                $url,
                PHP_URL_HOST
            );

        if (
            ! is_string($host)
            || trim($host) === ''
        ) {
            return false;
        }

        $token =
            mb_strtolower(
                preg_replace(
                    '/[^a-z0-9]+/',
                    '',
                    strtolower(
                        $token
                    )
                )
                ?? ''
            );

        if ($token === '') {
            return false;
        }

        return in_array(
            $token,
            $this->domainLabels(
                $host
            ),
            true
        );
    }

    private function matchesCorporateDomain(
        ?string $email,
        ?string $url,
    ): bool {
        if (
            ! is_string($email)
            || trim($email) === ''
            || ! is_string($url)
            || trim($url) === ''
        ) {
            return false;
        }

        $at =
            strrpos(
                $email,
                '@'
            );

        if ($at === false) {
            return false;
        }

        $emailDomain =
            mb_strtolower(
                trim(
                    substr(
                        $email,
                        $at + 1
                    )
                )
            );

        $host =
            parse_url(
                $url,
                PHP_URL_HOST
            );

        if (
            ! is_string($host)
            || trim($host) === ''
        ) {
            return false;
        }

        $emailLabels =
            $this->domainLabels(
                $emailDomain
            );

        $urlLabels =
            $this->domainLabels(
                $host
            );

        return array_intersect(
            $emailLabels,
            $urlLabels
        ) !== [];
    }

    /**
     * @return list<string>
     */
    private function domainLabels(
        string $domain
    ): array {
        $labels =
            explode(
                '.',
                mb_strtolower(
                    trim($domain)
                )
            );

        $result = [];

        foreach ($labels as $label) {
            $label =
                preg_replace(
                    '/[^a-z0-9]+/',
                    '',
                    $label
                )
                ?? '';

            if (
                strlen($label) < 4
                || in_array(
                    $label,
                    self::DOMAIN_NOISE,
                    true
                )
            ) {
                continue;
            }

            $result[] =
                $label;
        }

        return array_values(
            array_unique(
                $result
            )
        );
    }

    private function normalize(
        ?string $value
    ): string {
        return TextNormalizer::companyName(
            $value
        )
            ?? '';
    }
}
