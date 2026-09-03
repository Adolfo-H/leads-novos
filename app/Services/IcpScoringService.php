<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyIcpScore;
use App\Models\Establishment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class IcpScoringService
{
    public const VERSION = 'v2';

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

        $establishments =
            $this->eligibleEstablishments(
                $company
            );

        $score = 0;

        /**
         * @var array<string, array<string, mixed>>
         */
        $factors = [];

        /*
        |--------------------------------------------------------------------------
        | CNAE do grupo — até 30
        |--------------------------------------------------------------------------
        */

        $cnae = $this->cnaeFactor(
            $establishments
        );

        $score += $cnae['points'];

        $factors['cnae'] = [
            'points' => $cnae['points'],

            'max' => 30,

            'matched' => $cnae['code'],

            'establishment_cnpj' => $cnae['cnpj'],

            'reason' => $cnae['reason'],
        ];

        /*
        |--------------------------------------------------------------------------
        | Presença geográfica do grupo — 15
        |--------------------------------------------------------------------------
        */

        $states = [];

        foreach (
            $establishments as $establishment
        ) {
            if (! $establishment->state) {
                continue;
            }

            $states[] = mb_strtoupper(
                trim(
                    $establishment->state
                )
            );
        }

        $states = array_values(
            array_unique(
                $states
            )
        );

        $matchedStates =
            array_values(
                array_intersect(
                    $states,
                    self::PRIORITY_STATES
                )
            );

        $statePoints =
            $matchedStates !== []
                ? 15
                : 0;

        $score += $statePoints;

        $factors['state'] = [
            'points' => $statePoints,

            'max' => 15,

            'states' => $states,

            'matched_states' => $matchedStates,

            'matched' => $matchedStates !== [],

            'reason' => $matchedStates !== []
                    ? 'O grupo possui unidade ativa em estado prioritário.'
                    : 'Nenhuma unidade ativa do grupo está em estado prioritário.',
        ];

        /*
        |--------------------------------------------------------------------------
        | Porte — 15
        |--------------------------------------------------------------------------
        */

        $sizeCode = preg_replace(
            '/\D/',
            '',
            (string)
                $company->size_code
        );

        $sizeCode =
            $sizeCode !== ''
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

            'description' => $company
                ->size_description,

            'matched' => $sizeMatch,

            'reason' => $sizeMatch
                    ? 'Porte 05 - Demais.'
                    : 'Porte fora do perfil prioritário.',
        ];

        /*
        |--------------------------------------------------------------------------
        | Capital social — 15
        |--------------------------------------------------------------------------
        */

        $capital =
            $company
                ->share_capital !== null
                ? (float)
                    $company
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
        | Natureza jurídica — 15
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
        | Relevância regional — 10
        |--------------------------------------------------------------------------
        */

        $establishmentCount =
            $establishments->count();

        $regionalMatch =
            $establishmentCount > 1;

        $regionalPoints =
            $regionalMatch
                ? 10
                : 0;

        $score += $regionalPoints;

        $factors[
            'regional_relevance'
        ] = [
            'points' => $regionalPoints,

            'max' => 10,

            'establishments' => $establishmentCount,

            'states' => count($states),

            'matched' => $regionalMatch,

            'reason' => $regionalMatch
                    ? 'Grupo com múltiplos estabelecimentos operacionais.'
                    : 'Grupo sem múltiplos estabelecimentos operacionais conhecidos.',
        ];

        /*
        |--------------------------------------------------------------------------
        | Metadados do cálculo
        |--------------------------------------------------------------------------
        */

        $factors['_meta'] = [
            'scope' => 'company_group',

            'eligible_establishments' => $establishmentCount,

            'total_establishments' => $company
                ->establishments
                ->count(),

            'version' => self::VERSION,
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

    /**
     * Usa somente estabelecimentos ativos quando
     * a fonte possui informação cadastral.
     *
     * Para cadastros manuais/testes sem status,
     * mantém todos os estabelecimentos elegíveis.
     *
     * @return Collection<int, Establishment>
     */
    private function eligibleEstablishments(
        Company $company
    ): Collection {
        $all = $company
            ->establishments
            ->values();

        $hasKnownStatus =
            $all->contains(
                function (
                    Establishment $establishment
                ): bool {
                    return
                        trim(
                            (string)
                                $establishment
                                    ->registration_status_code
                        ) !== ''
                        || trim(
                            (string)
                                $establishment
                                    ->registration_status
                        ) !== '';
                }
            );

        if (! $hasKnownStatus) {
            return $all;
        }

        return $all
            ->filter(
                function (
                    Establishment $establishment
                ): bool {
                    $code = trim(
                        (string)
                            $establishment
                                ->registration_status_code
                    );

                    if ($code !== '') {
                        return $code === '02';
                    }

                    return $this->normalizeText(
                        $establishment
                            ->registration_status
                    ) === 'ATIVA';
                }
            )
            ->values();
    }

    /**
     * @param  Collection<int, Establishment>  $establishments
     * @return array{
     *     points: int,
     *     code: string|null,
     *     cnpj: string|null,
     *     reason: string
     * }
     */
    private function cnaeFactor(
        Collection $establishments
    ): array {
        $secondary = null;

        foreach (
            $establishments as $establishment
        ) {
            foreach (
                $establishment->cnaes as $cnae
            ) {
                if (
                    ! in_array(
                        $cnae->code,
                        self::FOCUS_CNAES,
                        true
                    )
                ) {
                    continue;
                }

                $isPrimary =
                    (bool) data_get(
                        $cnae,
                        'pivot.is_primary',
                        false
                    );

                if ($isPrimary) {
                    return [
                        'points' => 30,

                        'code' => $cnae->code,

                        'cnpj' => $establishment
                            ->cnpj,

                        'reason' => 'CNAE principal prioritário encontrado em unidade operacional do grupo.',
                    ];
                }

                $secondary ??= [
                    'points' => 20,

                    'code' => $cnae->code,

                    'cnpj' => $establishment
                        ->cnpj,

                    'reason' => 'CNAE secundário prioritário encontrado em unidade operacional do grupo.',
                ];
            }
        }

        return $secondary ?? [
            'points' => 0,

            'code' => null,

            'cnpj' => null,

            'reason' => 'Nenhum CNAE prioritário encontrado nas unidades operacionais do grupo.',
        ];
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
