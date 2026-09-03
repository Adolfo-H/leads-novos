<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyIcpScore;
use App\Models\Establishment;
use Illuminate\Support\Str;

final class IcpScoringService
{
    public const VERSION = 'v1';

    /**
     * @var list<string>
     */
    private const FOCUS_CNAES = [
        '4622200',
        '4632001',
        '0115600',
        '0111302',
        '1071600',
    ];

    /**
     * @var list<string>
     */
    private const PRIORITY_STATES = [
        'MA',
        'SP',
        'PA',
        'RO',
        'MT',
        'MS',
        'TO',
        'GO',
        'MG',
    ];

    /**
     * 204-6 Sociedade Anônima Aberta
     * 205-4 Sociedade Anônima Fechada
     * 214-3 Cooperativa
     *
     * @var list<string>
     */
    private const PRIORITY_LEGAL_NATURES = [
        '2046',
        '2054',
        '2143',
    ];

    public function calculate(
        Company $company
    ): CompanyIcpScore {
        $company->loadMissing([
            'establishments.cnaes',
        ]);

        $matrix = $this->matrix(
            $company
        );

        $score = 0;

        /**
         * @var array<string, array<string, mixed>>
         */
        $factors = [];

        /*
        |--------------------------------------------------------------------------
        | CNAE — até 30 pontos
        |--------------------------------------------------------------------------
        */

        $primaryCnae = $matrix
            ?->cnaes
            ->first(
                fn ($cnae): bool => (bool) data_get($cnae, 'pivot.is_primary', false)
            );

        $secondaryFocus = $matrix
            ?->cnaes
            ->first(
                fn ($cnae): bool => ! (bool) data_get($cnae, 'pivot.is_primary', false)
                    && in_array(
                        $cnae->code,
                        self::FOCUS_CNAES,
                        true
                    )
            );

        $cnaePoints = 0;
        $matchedCnae = null;
        $cnaeReason =
            'Nenhum CNAE foco identificado.';

        if (
            $primaryCnae
            && in_array(
                $primaryCnae->code,
                self::FOCUS_CNAES,
                true
            )
        ) {
            $cnaePoints = 30;
            $matchedCnae =
                $primaryCnae->code;

            $cnaeReason =
                'CNAE principal prioritário.';
        } elseif ($secondaryFocus) {
            $cnaePoints = 20;
            $matchedCnae =
                $secondaryFocus->code;

            $cnaeReason =
                'CNAE secundário prioritário.';
        }

        $score += $cnaePoints;

        $factors['cnae'] = [
            'points' => $cnaePoints,
            'max' => 30,
            'matched' => $matchedCnae,
            'reason' => $cnaeReason,
        ];

        /*
        |--------------------------------------------------------------------------
        | Estado — 15 pontos
        |--------------------------------------------------------------------------
        */

        $state = $matrix?->state
            ? mb_strtoupper(
                $matrix->state
            )
            : null;

        $stateMatch =
            $state !== null
            && in_array(
                $state,
                self::PRIORITY_STATES,
                true
            );

        $statePoints =
            $stateMatch
                ? 15
                : 0;

        $score += $statePoints;

        $factors['state'] = [
            'points' => $statePoints,
            'max' => 15,
            'value' => $state,
            'matched' => $stateMatch,
            'reason' => $stateMatch
                ? 'Estado prioritário.'
                : 'Estado fora da lista prioritária.',
        ];

        /*
        |--------------------------------------------------------------------------
        | Porte — 15 pontos
        |--------------------------------------------------------------------------
        */

        $sizeCode = preg_replace(
            '/\D/',
            '',
            (string) $company->size_code
        );

        $sizeCode = $sizeCode !== ''
            ? str_pad(
                $sizeCode,
                2,
                '0',
                STR_PAD_LEFT
            )
            : null;

        $sizeMatch =
            $sizeCode === '05';

        $sizePoints =
            $sizeMatch
                ? 15
                : 0;

        $score += $sizePoints;

        $factors['size'] = [
            'points' => $sizePoints,
            'max' => 15,
            'value' => $sizeCode,
            'description' => $company->size_description,

            'matched' => $sizeMatch,

            'reason' => $sizeMatch
                ? 'Porte 05 - Demais.'
                : 'Porte fora do perfil prioritário.',
        ];

        /*
        |--------------------------------------------------------------------------
        | Capital social — 15 pontos
        |--------------------------------------------------------------------------
        */

        $capital =
            $company->share_capital !== null
                ? (float) $company
                    ->share_capital
                : null;

        $capitalMatch =
            $capital !== null
            && $capital > 1_000_000;

        $capitalPoints =
            $capitalMatch
                ? 15
                : 0;

        $score += $capitalPoints;

        $factors['capital'] = [
            'points' => $capitalPoints,
            'max' => 15,
            'value' => $capital,
            'matched' => $capitalMatch,

            'reason' => $capitalMatch
                ? 'Capital social acima de R$ 1 milhão.'
                : 'Capital social não supera R$ 1 milhão.',
        ];

        /*
        |--------------------------------------------------------------------------
        | Natureza jurídica — 15 pontos
        |--------------------------------------------------------------------------
        */

        $legalCode = preg_replace(
            '/\D/',
            '',
            (string)
                $company
                    ->legal_nature_code
        );

        $legalDescription =
            $this->normalizeText(
                $company
                    ->legal_nature_description
            );

        $natureMatch =
            (
                $legalCode !== ''
                && in_array(
                    $legalCode,
                    self::PRIORITY_LEGAL_NATURES,
                    true
                )
            )
            || str_contains(
                $legalDescription,
                'COOPERATIVA'
            )
            || str_contains(
                $legalDescription,
                'SOCIEDADE ANONIMA'
            );

        $naturePoints =
            $natureMatch
                ? 15
                : 0;

        $score += $naturePoints;

        $factors['legal_nature'] = [
            'points' => $naturePoints,
            'max' => 15,
            'code' => $legalCode ?: null,
            'description' => $company
                ->legal_nature_description,

            'matched' => $natureMatch,

            'reason' => $natureMatch
                ? 'Cooperativa ou Sociedade Anônima.'
                : 'Natureza jurídica fora do perfil prioritário.',
        ];

        /*
        |--------------------------------------------------------------------------
        | Relevância regional — até 10 pontos
        |--------------------------------------------------------------------------
        */

        $establishmentCount =
            $company
                ->establishments
                ->count();

        $regionalMatch =
            $establishmentCount > 1;

        $regionalPoints =
            $regionalMatch
                ? 10
                : 0;

        $score += $regionalPoints;

        $factors['regional_relevance'] = [
            'points' => $regionalPoints,
            'max' => 10,
            'establishments' => $establishmentCount,

            'matched' => $regionalMatch,

            'reason' => $regionalMatch
                ? 'Empresa com múltiplos estabelecimentos conhecidos.'
                : 'Ainda não há múltiplos estabelecimentos conhecidos.',
        ];

        $score = min(
            100,
            $score
        );

        $grade = $this->grade(
            $score
        );

        return CompanyIcpScore::query()
            ->updateOrCreate(
                [
                    'company_id' => $company->id,
                ],
                [
                    'score' => $score,

                    'grade' => $grade,

                    'label' => $this->label(
                        $grade
                    ),

                    'version' => self::VERSION,

                    'factors' => $factors,

                    'calculated_at' => now(),
                ]
            );
    }

    private function matrix(
        Company $company
    ): ?Establishment {
        return $company
            ->establishments
            ->firstWhere(
                'type',
                'matrix'
            )
            ?? $company
                ->establishments
                ->first();
    }

    private function grade(
        int $score
    ): string {
        return match (true) {
            $score >= 75 => 'A',
            $score >= 55 => 'B',
            $score >= 35 => 'C',
            default => 'D',
        };
    }

    private function label(
        string $grade
    ): string {
        return match ($grade) {
            'A' => 'Alta aderência',

            'B' => 'Boa aderência',

            'C' => 'Aderência parcial',

            default => 'Baixa aderência',
        };
    }

    private function normalizeText(
        ?string $value
    ): string {
        if (! $value) {
            return '';
        }

        return mb_strtoupper(
            Str::ascii(
                trim($value)
            )
        );
    }
}
