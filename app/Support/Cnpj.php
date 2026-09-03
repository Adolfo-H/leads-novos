<?php

namespace App\Support;

use InvalidArgumentException;

final class Cnpj
{
    private const FIRST_DIGIT_WEIGHTS = [
        5, 4, 3, 2,
        9, 8, 7, 6,
        5, 4, 3, 2,
    ];

    private const SECOND_DIGIT_WEIGHTS = [
        6, 5, 4, 3, 2,
        9, 8, 7, 6,
        5, 4, 3, 2,
    ];

    public static function normalize(string $value): string
    {
        $value = mb_strtoupper($value);

        return preg_replace(
            '/[^A-Z0-9]/',
            '',
            $value
        ) ?? '';
    }

    public static function isWellFormed(string $value): bool
    {
        $value = self::normalize($value);

        /*
         * 12 primeiras posições:
         * números ou letras.
         *
         * 2 últimas posições:
         * dígitos verificadores numéricos.
         */
        return preg_match(
            '/^[A-Z0-9]{12}[0-9]{2}$/',
            $value
        ) === 1;
    }

    public static function isValid(string $value): bool
    {
        $value = self::normalize($value);

        if (! self::isWellFormed($value)) {
            return false;
        }

        /*
         * CNPJs numéricos formados pelo mesmo dígito
         * em todas as 14 posições são inválidos.
         *
         * Exemplos:
         * 00.000.000/0000-00
         * 11.111.111/1111-11
         * 22.222.222/2222-22
         */
        if (
            preg_match(
                '/^([0-9])\1{13}$/',
                $value
            ) === 1
        ) {
            return false;
        }

        $base = substr($value, 0, 12);
        $digits = substr($value, 12, 2);

        return hash_equals(
            self::calculateCheckDigits($base),
            $digits
        );
    }

    public static function assertValid(string $value): void
    {
        if (! self::isValid($value)) {
            throw new InvalidArgumentException(
                'O CNPJ informado é inválido.'
            );
        }
    }

    public static function calculateCheckDigits(
        string $firstTwelveCharacters
    ): string {
        $base = self::normalize(
            $firstTwelveCharacters
        );

        if (
            strlen($base) !== 12
            || preg_match('/^[A-Z0-9]{12}$/', $base) !== 1
        ) {
            throw new InvalidArgumentException(
                'A base do CNPJ deve possuir exatamente 12 caracteres alfanuméricos.'
            );
        }

        $firstDigit = self::calculateDigit(
            $base,
            self::FIRST_DIGIT_WEIGHTS
        );

        $secondDigit = self::calculateDigit(
            $base.$firstDigit,
            self::SECOND_DIGIT_WEIGHTS
        );

        return (string) $firstDigit
            .(string) $secondDigit;
    }

    public static function root(string $value): string
    {
        $value = self::normalize($value);

        if (strlen($value) < 8) {
            throw new InvalidArgumentException(
                'Não foi possível identificar a raiz do CNPJ.'
            );
        }

        return substr($value, 0, 8);
    }

    public static function order(string $value): string
    {
        $value = self::normalize($value);

        if (strlen($value) !== 14) {
            throw new InvalidArgumentException(
                'CNPJ deve possuir 14 caracteres.'
            );
        }

        return substr($value, 8, 4);
    }

    public static function checkDigits(string $value): string
    {
        $value = self::normalize($value);

        if (strlen($value) !== 14) {
            throw new InvalidArgumentException(
                'CNPJ deve possuir 14 caracteres.'
            );
        }

        return substr($value, 12, 2);
    }

    public static function format(string $value): string
    {
        $value = self::normalize($value);

        if (! self::isWellFormed($value)) {
            throw new InvalidArgumentException(
                'CNPJ possui formato inválido.'
            );
        }

        return sprintf(
            '%s.%s.%s/%s-%s',
            substr($value, 0, 2),
            substr($value, 2, 3),
            substr($value, 5, 3),
            substr($value, 8, 4),
            substr($value, 12, 2),
        );
    }

    public static function isAlphanumeric(
        string $value
    ): bool {
        $value = self::normalize($value);

        return preg_match(
            '/[A-Z]/',
            substr($value, 0, 12)
        ) === 1;
    }

    /** @param list<int> $weights */
    private static function calculateDigit(
        string $value,
        array $weights
    ): int {
        $sum = 0;

        foreach (str_split($value) as $index => $character) {
            /*
             * Regra oficial do CNPJ alfanumérico:
             * valor ASCII do caractere - 48.
             *
             * Para números, isso mantém:
             * "0" => 0
             * "1" => 1
             *
             * Para letras:
             * "A" => 17
             * "B" => 18
             * etc.
             */
            $numericValue = ord($character) - 48;

            $sum += $numericValue * $weights[$index];
        }

        $remainder = $sum % 11;

        if ($remainder < 2) {
            return 0;
        }

        return 11 - $remainder;
    }
}
