<?php

namespace App\Contracts;

interface CnpjGroupDataProvider
{
    public function name(): string;

    /**
     * @return array{
     *     company: array<string, mixed>,
     *     establishments: list<array{
     *         establishment: array<string, mixed>,
     *         cnaes: list<array{
     *             code: string,
     *             description: string|null,
     *             is_primary: bool
     *         }>
     *     }>
     * }
     */
    public function lookupRoot(
        string $cnpjRoot
    ): array;
}
