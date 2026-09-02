<?php

namespace App\Support;

use Illuminate\Support\Str;

final class TextNormalizer
{
    public static function companyName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = Str::ascii($value);
        $value = mb_strtoupper($value);

        $value = preg_replace(
            '/[^A-Z0-9]+/',
            ' ',
            $value
        );

        return preg_replace(
            '/\s+/',
            ' ',
            trim((string) $value)
        );
    }
}
