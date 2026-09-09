<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyExportEvidence;
use App\Models\CompanyExportIntelligence;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class ExportIntelligenceScoringService
{
    /**
     * @var list<string>
     */
    private const DIMENSIONS = [
        'direct',
        'indirect',
        'trading',
    ];

    public function recalculate(
        Company $company
    ): CompanyExportIntelligence {
        $intelligence =
            $this->recalculateStoredEvidence(
                $company
            );

        $intelligence->update([
            'researched_at' => now(),
        ]);

        return $intelligence->refresh();
    }

    public function recalculateStoredEvidence(
        Company $company
    ): CompanyExportIntelligence {
        $intelligence =
            $this->ensureIntelligence(
                $company
            );

        foreach (
            self::DIMENSIONS as $dimension
        ) {
            $this->calculateDimension(
                company: $company,
                intelligence: $intelligence,
                dimension: $dimension,
            );

            $intelligence->refresh();
        }

        return $intelligence->refresh();
    }

    public function recalculateDimension(
        Company $company,
        string $dimension,
    ): CompanyExportIntelligence {
        $this->validateDimension(
            $dimension
        );

        $intelligence =
            $this->ensureIntelligence(
                $company
            );

        $this->calculateDimension(
            company: $company,
            intelligence: $intelligence,
            dimension: $dimension,
        );

        $intelligence->update([
            'researched_at' => now(),
        ]);

        return $intelligence->refresh();
    }

    private function calculateDimension(
        Company $company,
        CompanyExportIntelligence $intelligence,
        string $dimension,
    ): void {
        $this->validateDimension(
            $dimension
        );

        $confirmedPositiveCount =
            $this->evidenceQuery(
                $company,
                $dimension,
            )
                ->where(
                    'is_confirmed',
                    true
                )
                ->where(
                    'signal',
                    'positive'
                )
                ->count();

        $confirmedNegativeCount =
            $this->evidenceQuery(
                $company,
                $dimension,
            )
                ->where(
                    'is_confirmed',
                    true
                )
                ->where(
                    'signal',
                    'negative'
                )
                ->count();

        $confirmedEvidenceCount =
            $confirmedPositiveCount
            + $confirmedNegativeCount;

        /*
         * Se o usuário confirmou manualmente
         * a classificação e não há evidência
         * confirmada disputando essa decisão,
         * a automação não pode sobrescrevê-la.
         */
        if (
            (bool) $intelligence->getAttribute(
                $dimension.'_confirmed'
            )
            && $confirmedEvidenceCount === 0
        ) {
            return;
        }

        /*
         * Evidência confirmada sempre tem
         * prioridade máxima.
         */
        if (
            $confirmedPositiveCount > 0
            && $confirmedNegativeCount > 0
        ) {
            $this->saveResult(
                intelligence: $intelligence,
                dimension: $dimension,
                status: 'uncertain',
                confidence: 100,
                confirmed: false,
                company: $company,
                reason: 'Há evidências confirmadas '
                    .'conflitantes.',
            );

            return;
        }

        if ($confirmedPositiveCount > 0) {
            $this->saveResult(
                intelligence: $intelligence,
                dimension: $dimension,
                status: 'yes',
                confidence: 100,
                confirmed: true,
                company: $company,
                reason: 'Há evidência positiva '
                    .'confirmada.',
            );

            return;
        }

        if ($confirmedNegativeCount > 0) {
            $this->saveResult(
                intelligence: $intelligence,
                dimension: $dimension,
                status: 'no',
                confidence: 100,
                confirmed: true,
                company: $company,
                reason: 'Há evidência negativa '
                    .'confirmada.',
            );

            return;
        }

        $positiveConfidences =
            $this->confidenceValues(
                company: $company,
                dimension: $dimension,
                signal: 'positive',
            );

        $negativeConfidences =
            $this->confidenceValues(
                company: $company,
                dimension: $dimension,
                signal: 'negative',
            );

        $neutralConfidences =
            $this->confidenceValues(
                company: $company,
                dimension: $dimension,
                signal: 'neutral',
            );

        $positive =
            $this->aggregateConfidence(
                $positiveConfidences
            );

        $negative =
            $this->aggregateConfidence(
                $negativeConfidences
            );

        /*
         * Evidências neutras representam
         * cobertura da pesquisa, não força
         * de uma conclusão.
         *
         * Por isso elas não são acumuladas.
         * Vários resultados neutros não podem
         * transformar ausência de conclusão
         * em confiança alta.
         */
        $neutral =
            max([
                0,
                ...$neutralConfidences,
            ]);

        /*
         * Nenhuma evidência ainda.
         */
        if (
            $positive === 0
            && $negative === 0
            && $neutral === 0
        ) {
            $this->saveResult(
                intelligence: $intelligence,
                dimension: $dimension,
                status: 'not_researched',
                confidence: 0,
                confirmed: false,
                company: $company,
                reason: 'Nenhuma evidência registrada.',
            );

            return;
        }

        /*
         * Somente evidências neutras:
         * pesquisamos, mas não conseguimos
         * concluir.
         */
        if (
            $positive === 0
            && $negative === 0
        ) {
            $this->saveResult(
                intelligence: $intelligence,
                dimension: $dimension,
                status: 'uncertain',
                confidence: $neutral,
                confirmed: false,
                company: $company,
                reason: 'Existem evidências, mas '
                    .'nenhuma indica conclusão.',
            );

            return;
        }

        $difference =
            $positive
            - $negative;

        /*
         * Para transformar evidência pública
         * em SIM/NÃO exigimos:
         *
         * - confiança agregada mínima de 65
         * - vantagem mínima de 20 pontos
         *
         * Isso evita conclusões agressivas
         * com sinais fracos ou conflitantes.
         */
        if (
            $positive >= 65
            && $difference >= 20
        ) {
            $this->saveResult(
                intelligence: $intelligence,
                dimension: $dimension,
                status: 'yes',
                confidence: $positive,
                confirmed: false,
                company: $company,
                reason: 'Predominância de evidências '
                    .'positivas.',
            );

            return;
        }

        if (
            $negative >= 65
            && $difference <= -20
        ) {
            $this->saveResult(
                intelligence: $intelligence,
                dimension: $dimension,
                status: 'no',
                confidence: $negative,
                confirmed: false,
                company: $company,
                reason: 'Predominância de evidências '
                    .'negativas.',
            );

            return;
        }

        $this->saveResult(
            intelligence: $intelligence,
            dimension: $dimension,
            status: 'uncertain',
            confidence: max(
                $positive,
                $negative,
            ),
            confirmed: false,
            company: $company,
            reason: 'As evidências ainda não são '
                .'suficientes para uma conclusão.',
        );
    }

    /**
     * @return list<int>
     */
    private function confidenceValues(
        Company $company,
        string $dimension,
        string $signal,
    ): array {
        $values =
            $this->evidenceQuery(
                $company,
                $dimension,
            )
                ->where(
                    'signal',
                    $signal
                )
                ->pluck(
                    'confidence'
                )
                ->map(
                    fn (mixed $value): int => (int) $value
                )
                ->all();

        return array_values(
            $values
        );
    }

    /**
     * Combina múltiplas evidências sem
     * simplesmente somar percentuais.
     *
     * Exemplo:
     * 50% + 50% => 75%, não 100%.
     *
     * Evidência automática nunca chega
     * sozinha a 100%; esse nível é reservado
     * para confirmação humana/evidência
     * confirmada.
     *
     * @param  list<int>  $confidences
     */
    private function aggregateConfidence(
        array $confidences
    ): int {
        if ($confidences === []) {
            return 0;
        }

        $remaining =
            1.0;

        foreach (
            $confidences as $confidence
        ) {
            $normalized =
                max(
                    0,
                    min(
                        100,
                        $confidence
                    )
                ) / 100;

            $remaining *=
                1 - $normalized;
        }

        $aggregated =
            (int) round(
                (
                    1 - $remaining
                ) * 100
            );

        return min(
            95,
            $aggregated
        );
    }

    private function saveResult(
        CompanyExportIntelligence $intelligence,
        string $dimension,
        string $status,
        int $confidence,
        bool $confirmed,
        Company $company,
        string $reason,
    ): void {
        $positiveCount =
            $this->evidenceQuery(
                $company,
                $dimension,
            )
                ->where(
                    'signal',
                    'positive'
                )
                ->count();

        $negativeCount =
            $this->evidenceQuery(
                $company,
                $dimension,
            )
                ->where(
                    'signal',
                    'negative'
                )
                ->count();

        $neutralCount =
            $this->evidenceQuery(
                $company,
                $dimension,
            )
                ->where(
                    'signal',
                    'neutral'
                )
                ->count();

        $rawMetadata =
            $intelligence->getAttribute(
                'metadata'
            );

        /** @var array<string, mixed> $metadata */
        $metadata =
            is_array($rawMetadata)
                ? $rawMetadata
                : [];

        $metadata['scoring'][
            $dimension
        ] = [
            'version' => 'v2',

            'status' => $status,

            'confidence' => $confidence,

            'reason' => $reason,

            'positive_evidence_count' => $positiveCount,

            'negative_evidence_count' => $negativeCount,

            'neutral_evidence_count' => $neutralCount,

            'calculated_at' => now()->toIso8601String(),
        ];

        $intelligence->update([
            $dimension.'_status' => $status,

            $dimension.'_confidence' => $confidence,

            $dimension.'_confirmed' => $confirmed,

            'metadata' => $metadata,
        ]);
    }

    /**
     * @return Builder<CompanyExportEvidence>
     */
    private function evidenceQuery(
        Company $company,
        string $dimension,
    ): Builder {
        return CompanyExportEvidence::query()
            ->where(
                'company_id',
                $company->id
            )
            ->where(
                'dimension',
                $dimension
            );
    }

    private function ensureIntelligence(
        Company $company
    ): CompanyExportIntelligence {
        return CompanyExportIntelligence::query()
            ->firstOrCreate(
                [
                    'company_id' => $company->id,
                ],
                [
                    'direct_status' => 'not_researched',

                    'direct_confidence' => 0,

                    'direct_confirmed' => false,

                    'indirect_status' => 'not_researched',

                    'indirect_confidence' => 0,

                    'indirect_confirmed' => false,

                    'trading_status' => 'not_researched',

                    'trading_confidence' => 0,

                    'trading_confirmed' => false,

                    'metadata' => [],
                ]
            );
    }

    private function validateDimension(
        string $dimension
    ): void {
        if (
            ! in_array(
                $dimension,
                self::DIMENSIONS,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Dimensão de exportação inválida: '
                .$dimension
            );
        }
    }
}
