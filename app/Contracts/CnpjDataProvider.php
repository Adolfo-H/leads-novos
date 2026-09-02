<?php

namespace App\Contracts;

interface CnpjDataProvider
{
    public function name(): string;

    public function lookup(string $cnpj): array;
}
