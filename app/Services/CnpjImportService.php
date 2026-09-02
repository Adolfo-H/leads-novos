<?php

namespace App\Services;

use App\Models\Establishment;
use App\Models\ImportBatch;
use App\Support\Cnpj;
use Illuminate\Support\Facades\DB;

final class CnpjImportService
{
    public function import(
        array $values,
        ?int $userId = null,
        string $sourceType = 'manual',
        ?string $filename = null,
    ): ImportBatch {
        return DB::transaction(function () use (
            $values,
            $userId,
            $sourceType,
            $filename,
        ) {
            $batch = ImportBatch::create([
                'user_id' => $userId,
                'source_type' => $sourceType,
                'original_filename' => $filename,
                'status' => 'processing',
            ]);

            $seen = [];

            $valid = 0;
            $invalid = 0;
            $duplicates = 0;
            $existing = 0;

            foreach (
                array_values($values) as $index => $rawValue
            ) {
                $raw = trim(
                    (string) $rawValue
                );

                if ($raw === '') {
                    continue;
                }

                $normalized = Cnpj::normalize(
                    $raw
                );

                $status = 'pending';
                $error = null;
                $companyId = null;

                if (
                    ! Cnpj::isValid(
                        $normalized
                    )
                ) {
                    $status = 'invalid';

                    $error =
                        'CNPJ inválido.';

                    $invalid++;
                } elseif (
                    isset(
                        $seen[$normalized]
                    )
                ) {
                    $status = 'duplicate';

                    $duplicates++;
                } else {
                    $seen[$normalized] = true;

                    $establishment =
                        Establishment::query()
                            ->where(
                                'cnpj',
                                $normalized
                            )
                            ->first();

                    if ($establishment) {
                        $status = 'existing';

                        $companyId =
                            $establishment
                                ->company_id;

                        $existing++;
                    } else {
                        $status = 'ready';

                        $valid++;
                    }
                }

                $batch->items()->create([
                    'row_number' => $index + 1,

                    'raw_cnpj' => $raw,

                    'normalized_cnpj' => $normalized !== ''
                            ? $normalized
                            : null,

                    'status' => $status,

                    'company_id' => $companyId,

                    'error_message' => $error,
                ]);
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

    public function parseText(
        string $input
    ): array {
        return collect(
            preg_split(
                '/[\r\n,;]+/',
                $input
            )
        )
            ->map(
                fn ($value) => trim($value)
            )
            ->filter()
            ->values()
            ->all();
    }
}
