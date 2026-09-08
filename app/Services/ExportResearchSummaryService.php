<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyExportEvidence;
use App\Models\CompanyExportIntelligence;
use Illuminate\Support\Str;

final class ExportResearchSummaryService
{
    /**
     * @var array<string, string>
     */
    private const DIMENSIONS = [
        'direct' => 'Exportação direta',

        'indirect' => 'Exportação indireta',

        'trading' => 'Relação com trading',
    ];

    /**
     * Termos que fazem sentido comercial
     * para o ICP atual do Prospector.
     *
     * @var array<string, list<string>>
     */
    private const PRODUCTS = [
        'Soja' => [
            'soja',
            'soybean',
            'soybeans',
        ],

        'Milho' => [
            'milho',
            'corn',
            'maize',
        ],

        'Algodão' => [
            'algodao',
            'cotton',
        ],

        'Açúcar' => [
            'acucar',
            'sugar',
        ],

        'Etanol' => [
            'etanol',
            'ethanol',
        ],

        'Café' => [
            'cafe',
            'coffee',
        ],

        'Trigo' => [
            'trigo',
            'wheat',
        ],

        'Maçã' => [
            'maca',
            'macas',
            'apple',
            'apples',
        ],

        'Frutas' => [
            'fruta',
            'frutas',
            'fruit',
            'fruits',
        ],

        'Carne bovina' => [
            'carne bovina',
            'carne de boi',
            'beef',
        ],

        'Frango' => [
            'frango',
            'aves',
            'poultry',
            'chicken',
        ],

        'Fertilizantes' => [
            'fertilizante',
            'fertilizantes',
            'fertilizer',
            'fertilizers',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const MARKETS = [
        'China' => [
            'china',
            'chines',
            'chinesa',
            'chineses',
        ],

        'Estados Unidos' => [
            'estados unidos',
            'eua',
            'united states',
        ],

        'Europa' => [
            'europa',
            'europeu',
            'europeia',
        ],

        'Ásia' => [
            'asia',
            'asiatico',
            'asiatica',
        ],

        'Oriente Médio' => [
            'oriente medio',
            'middle east',
        ],

        'América Latina' => [
            'america latina',
            'latin america',
        ],

        'Mercosul' => [
            'mercosul',
            'mercosur',
        ],

        'Argentina' => [
            'argentina',
        ],

        'Chile' => [
            'chile',
        ],
    ];

    /**
     * @return array{
     *     headline: string,
     *     evidence_count: int,
     *     positive_count: int,
     *     neutral_count: int,
     *     negative_count: int,
     *     products: list<string>,
     *     markets: list<string>,
     *     dimensions: array<string, array{
     *         label: string,
     *         status: string,
     *         confidence: int,
     *         evidence_count: int,
     *         best_evidence: array{
     *             title: string|null,
     *             text: string,
     *             source_name: string|null,
     *             source_url: string|null,
     *             signal: string,
     *             confidence: int
     *         }|null
     *     }>,
     *     generated_at: string
     * }
     */
    public function build(
        Company $company,
        CompanyExportIntelligence $intelligence,
    ): array {
        $evidences =
            CompanyExportEvidence::query()
                ->where(
                    'company_id',
                    $company->id
                )
                ->get();

        $positiveCount =
            $evidences
                ->where(
                    'signal',
                    'positive'
                )
                ->count();

        $neutralCount =
            $evidences
                ->where(
                    'signal',
                    'neutral'
                )
                ->count();

        $negativeCount =
            $evidences
                ->where(
                    'signal',
                    'negative'
                )
                ->count();

        $dimensions = [];

        foreach (
            self::DIMENSIONS as $dimension => $label
        ) {
            $dimensionEvidences =
                $evidences
                    ->where(
                        'dimension',
                        $dimension
                    );

            $status =
                $intelligence
                    ->getAttribute(
                        $dimension
                        .'_status'
                    );

            $rawConfidence =
                $intelligence
                    ->getAttribute(
                        $dimension
                        .'_confidence'
                    );

            $confidence =
                is_numeric(
                    $rawConfidence
                )
                    ? (int) $rawConfidence
                    : 0;

            $best =
                $dimensionEvidences
                    ->sortByDesc(
                        fn (
                            CompanyExportEvidence $evidence
                        ): int => $this
                            ->evidenceRank(
                                $evidence
                            )
                    )
                    ->first();

            $dimensions[
                $dimension
            ] = [
                'label' => $label,

                'status' => is_string($status)
                        ? $status
                        : 'not_researched',

                'confidence' => $confidence,

                'evidence_count' => $dimensionEvidences
                    ->count(),

                'best_evidence' => $best
                        ? [
                            'title' => $best->title,

                            'text' => Str::limit(
                                $best
                                    ->evidence_text,
                                280
                            ),

                            'source_name' => $best
                                ->source_name,

                            'source_url' => $best
                                ->source_url,

                            'signal' => $best->signal,

                            'confidence' => $best
                                ->confidence,
                        ]
                        : null,
            ];
        }

        $combinedText =
            $evidences
                ->map(
                    fn (
                        CompanyExportEvidence $evidence
                    ): string => trim(
                        (
                            $evidence->title
                            ?? ''
                        )
                        .' '
                        .$evidence
                            ->evidence_text
                    )
                )
                ->implode(' ');

        $products =
            $this->extractTerms(
                text: $combinedText,

                dictionary: self::PRODUCTS,
            );

        $markets =
            $this->extractTerms(
                text: $combinedText,

                dictionary: self::MARKETS,
            );

        return [
            'headline' => $this->headline(
                dimensions: $dimensions,

                evidenceCount: $evidences
                    ->count(),
            ),

            'evidence_count' => $evidences
                ->count(),

            'positive_count' => $positiveCount,

            'neutral_count' => $neutralCount,

            'negative_count' => $negativeCount,

            'products' => $products,

            'markets' => $markets,

            'dimensions' => $dimensions,

            'generated_at' => now()
                ->toIso8601String(),
        ];
    }

    private function evidenceRank(
        CompanyExportEvidence $evidence
    ): int {
        $signalWeight =
            match (
                $evidence->signal
            ) {
                'positive' => 3,

                'negative' => 2,

                default => 1,
            };

        return (
            $signalWeight
            * 1000
        )
        + $evidence->confidence;
    }

    /**
     * @param  array<string, array{
     *     label: string,
     *     status: string,
     *     confidence: int,
     *     evidence_count: int,
     *     best_evidence: array<string, mixed>|null
     * }>  $dimensions
     */
    private function headline(
        array $dimensions,
        int $evidenceCount,
    ): string {
        if ($evidenceCount === 0) {
            return
                'A pesquisa foi concluída, '
                .'mas nenhuma evidência pública '
                .'relevante foi encontrada.';
        }

        $confirmed = [];

        foreach (
            $dimensions as $dimension
        ) {
            if (
                $dimension[
                    'status'
                ] === 'yes'
            ) {
                $confirmed[] =
                    mb_strtolower(
                        $dimension[
                            'label'
                        ]
                    );
            }
        }

        if ($confirmed !== []) {
            return
                'A pesquisa encontrou evidências '
                .'compatíveis com '
                .$this->naturalList(
                    $confirmed
                )
                .'.';
        }

        $allNegative =
            collect(
                $dimensions
            )
                ->every(
                    fn (
                        array $dimension
                    ): bool => $dimension[
                            'status'
                        ] === 'no'
                );

        if ($allNegative) {
            return
                'As fontes pesquisadas não '
                .'sustentam, neste momento, '
                .'uma atuação exportadora nas '
                .'modalidades analisadas.';
        }

        return
            'Há indícios públicos relacionados '
            .'à atividade exportadora, mas as '
            .'fontes ainda não permitem confirmar '
            .'com segurança se a operação é '
            .'direta, indireta ou realizada '
            .'por meio de trading.';
    }

    /**
     * @param  array<string, list<string>>  $dictionary
     * @return list<string>
     */
    private function extractTerms(
        string $text,
        array $dictionary,
    ): array {
        $normalized =
            $this->normalize(
                $text
            );

        $found = [];

        foreach (
            $dictionary as $label => $terms
        ) {
            foreach (
                $terms as $term
            ) {
                $normalizedTerm =
                    $this->normalize(
                        $term
                    );

                if (
                    str_contains(
                        ' '.$normalized.' ',
                        ' '.$normalizedTerm.' '
                    )
                ) {
                    $found[] =
                        $label;

                    break;
                }
            }
        }

        return array_values(
            array_unique(
                $found
            )
        );
    }

    private function normalize(
        string $value
    ): string {
        $value =
            mb_strtolower(
                Str::ascii(
                    $value
                )
            );

        $normalized =
            preg_replace(
                '/[^a-z0-9]+/',
                ' ',
                $value
            );

        return trim(
            $normalized
            ?? $value
        );
    }

    /**
     * @param  list<string>  $values
     */
    private function naturalList(
        array $values
    ): string {
        if (
            count($values)
            === 1
        ) {
            return $values[0];
        }

        $last =
            array_pop(
                $values
            );

        return implode(
            ', ',
            $values
        )
        .' e '
        .$last;
    }
}
