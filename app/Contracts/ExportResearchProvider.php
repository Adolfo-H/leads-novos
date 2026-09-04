<?php

namespace App\Contracts;

use App\Models\Company;

interface ExportResearchProvider
{
    public function name(): string;

    /**
     * O provider recebe as consultas planejadas
     * e devolve evidências já estruturadas.
     *
     * @param  list<string>  $queries
     * @return list<array{
     *     dimension: string,
     *     signal: string,
     *     confidence: int,
     *     source_type: string,
     *     source_name: string|null,
     *     source_url: string|null,
     *     title: string|null,
     *     evidence_text: string,
     *     metadata: array<string, mixed>
     * }>
     */
    public function research(
        Company $company,
        array $queries,
    ): array;
}
