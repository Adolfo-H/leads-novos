<?php

namespace App\Services;

use App\Models\Establishment;
use App\Models\ImportBatch;
use App\Support\Cnpj;
use Illuminate\Support\Facades\DB;

final class CnpjImportService
{
    /**
     * @param  array<int, string|int|float>  $values
     */
    public function import(
        array $values,
        ?int $userId = null,
        string $sourceType = 'manual',
        ?string $filename = null,
    ): ImportBatch {
        $maxRows = max(
            1,
            min(
                10000,
                (int) config(
                    'prospector.imports.max_rows',
                    5000
                )
            )
        );

        if (count($values) > $maxRows) {
            throw new \InvalidArgumentException(
                'O lote excede o limite de '
                .$maxRows
                .' registros por importação.'
            );
        }

        return DB::transaction(function () use (
            $values,
            $userId,
            $sourceType,
            $filename,
        ): ImportBatch {
            $batch = ImportBatch::create([
                'user_id' => $userId,
                'source_type' => $sourceType,
                'original_filename' => $filename,
                'status' => 'processing',
            ]);

            /** @var array<string, true> $seen */
            $seen = [];

            $valid = 0;
            $invalid = 0;
            $duplicates = 0;
            $existing = 0;

            // Bound IN lists below SQLite's parameter limit and avoid one lookup per row.
            foreach (
                array_chunk(
                    array_values($values),
                    500,
                    true
                ) as $chunk
            ) {
                $cnpjsToLookup = [];

                foreach ($chunk as $rawValue) {
                    $raw = trim(
                        (string) $rawValue
                    );

                    if ($raw === '') {
                        continue;
                    }

                    $normalized = Cnpj::normalize(
                        $raw
                    );

                    if (Cnpj::isValid($normalized)) {
                        $cnpjsToLookup[] = $normalized;
                    }
                }

                $existingByCnpj = [];

                if ($cnpjsToLookup !== []) {
                    $establishments =
                        Establishment::query()
                            ->whereIn(
                                'cnpj',
                                array_values(
                                    array_unique(
                                        $cnpjsToLookup
                                    )
                                )
                            )
                            ->get([
                                'cnpj',
                                'company_id',
                            ]);

                    foreach ($establishments as $establishment) {
                        $existingByCnpj[
                            (string) $establishment->cnpj
                        ] = $establishment->company_id;
                    }
                }

                foreach ($chunk as $index => $rawValue) {
                    $raw = trim(
                        (string) $rawValue
                    );

                    if ($raw === '') {
                        continue;
                    }

                    $normalized = Cnpj::normalize(
                        $raw
                    );

                    $isValid =
                        Cnpj::isValid(
                            $normalized
                        );

                    $cnpjRoot =
                        $isValid
                            ? Cnpj::root(
                                $normalized
                            )
                            : null;

                    $status = 'pending';
                    $error = null;
                    $companyId = null;

                    if (! $isValid) {
                        $status = 'invalid';
                        $error = 'CNPJ inválido.';
                        $invalid++;
                    } elseif (isset($seen[$normalized])) {
                        $status = 'duplicate';
                        $duplicates++;
                    } else {
                        $seen[$normalized] = true;

                        if (
                            array_key_exists(
                                $normalized,
                                $existingByCnpj
                            )
                        ) {
                            $status = 'existing';
                            $companyId =
                                $existingByCnpj[$normalized];
                            $existing++;
                        } else {
                            $status = 'ready';
                            $valid++;
                        }
                    }

                    /*
                     * raw_cnpj possui limite de 50 caracteres.
                     *
                     * Uma linha inválida e muito longa não
                     * pode derrubar a transação inteira.
                     */
                    $rawLength =
                        mb_strlen(
                            $raw
                        );

                    $rawForStorage =
                        mb_substr(
                            $raw,
                            0,
                            50
                        );

                    $metadata =
                        $rawLength > 50
                            ? [
                                'input' => [
                                    'raw_truncated' => true,

                                    'raw_length' => $rawLength,
                                ],
                            ]
                            : null;

                    $batch->items()->create([
                        'row_number' => $index + 1,

                        'raw_cnpj' => $rawForStorage,

                        /*
                         * normalized_cnpj possui 14 caracteres.
                         *
                         * Só gravamos quando o valor
                         * efetivamente for um CNPJ válido.
                         */
                        'normalized_cnpj' => $isValid
                                ? $normalized
                                : null,

                        'cnpj_root' => $cnpjRoot,

                        'status' => $status,

                        'company_id' => $companyId,

                        'error_message' => $error,

                        'metadata' => $metadata,
                    ]);
                }
            }

            $batch->update([
                'status' => 'ready',

                'total_rows' => $batch
                    ->items()
                    ->count(),

                'valid_rows' => $valid,

                'invalid_rows' => $invalid,

                'duplicate_rows' => $duplicates,

                'existing_rows' => $existing,
            ]);

            return $batch->fresh([
                'items',
            ]);
        });
    }

    /**
     * @return list<string>
     */
    public function parseText(
        string $input
    ): array {
        $parts = preg_split(
            '/[\r\n,;]+/',
            $input
        );

        if ($parts === false) {
            return [];
        }

        $values = collect($parts)
            ->map(
                fn (string $value): string => trim($value)
            )
            ->filter(
                fn (string $value): bool => $value !== ''
            )
            ->values()
            ->all();

        return array_values($values);
    }
}
