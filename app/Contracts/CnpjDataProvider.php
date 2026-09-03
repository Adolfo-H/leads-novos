<?php

namespace App\Contracts;

interface CnpjDataProvider
{
    public function name(): string;

    /**
     * @return array<string, mixed>
     */
    public function lookup(string $cnpj): array;
}
