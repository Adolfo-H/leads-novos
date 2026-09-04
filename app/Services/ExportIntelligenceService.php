<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyExportEvidence;
use App\Models\CompanyExportIntelligence;
use DateTimeInterface;
use InvalidArgumentException;

final class ExportIntelligenceService
{
    /**
     * @var array<string, string>
     */
    private const DIMENSIONS = [
        'direct' => 'direct',
        'indirect' => 'indirect',
        'trading' => 'trading',
    ];

    /**
     * @var list<string>
     */
    private const STATUSES = [
        'not_researched',
        'yes',
        'no',
        'uncertain',
    ];

    /**
     * @var list<string>
     */
    private const SIGNALS = [
        'positive',
        'negative',
        'neutral',
    ];

    public function __construct(
        private readonly ExportIntelligenceScoringService $scoring,
        private readonly SdrScoringService $sdr,
    ) {}

    public function ensure(
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

    public function classify(
        Company $company,
        string $dimension,
        string $status,
        int $confidence,
        bool $confirmed = false,
        ?string $summary = null,
    ): CompanyExportIntelligence {
        $prefix =
            $this->dimensionPrefix(
                $dimension
            );

        if (
            ! in_array(
                $status,
                self::STATUSES,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Status de exportação inválido: '
                .$status
            );
        }

        $this->validateConfidence(
            $confidence
        );

        $intelligence =
            $this->ensure(
                $company
            );

        $updates = [
            $prefix.'_status' => $status,

            $prefix.'_confidence' => $confidence,

            $prefix.'_confirmed' => $confirmed,

            'researched_at' => now(),
        ];

        if ($summary !== null) {
            $updates[
                $prefix.'_summary'
            ] = $summary;
        }

        $intelligence->update(
            $updates
        );

        /*
         * A classificação de exportação mudou,
         * então a prioridade comercial também
         * pode ter mudado.
         */
        $company->unsetRelation(
            'exportIntelligence'
        );

        $this->sdr->recalculate(
            $company
        );

        return $intelligence->refresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordEvidence(
        Company $company,
        string $dimension,
        string $signal,
        string $sourceType,
        string $evidenceText,
        int $confidence = 50,
        bool $confirmed = false,
        ?string $sourceName = null,
        ?string $sourceUrl = null,
        ?string $title = null,
        ?DateTimeInterface $observedAt = null,
        array $metadata = [],
    ): CompanyExportEvidence {
        $this->dimensionPrefix(
            $dimension
        );

        if (
            ! in_array(
                $signal,
                self::SIGNALS,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'Sinal de evidência inválido: '
                .$signal
            );
        }

        $this->validateConfidence(
            $confidence
        );

        $this->ensure(
            $company
        );

        $fingerprint =
            $this->evidenceFingerprint(
                company: $company,
                dimension: $dimension,
                sourceType: $sourceType,
                sourceUrl: $sourceUrl,
                title: $title,
                evidenceText: $evidenceText,
            );

        /*
         * A identidade da evidência é o
         * fingerprint.
         *
         * A mesma evidência encontrada em uma
         * nova pesquisa deve atualizar o mesmo
         * registro, nunca criar outra linha.
         */
        $evidence =
            CompanyExportEvidence::query()
                ->where(
                    'fingerprint',
                    $fingerprint
                )
                ->first();

        if (! $evidence) {
            $evidence =
                new CompanyExportEvidence;
        }

        /*
         * forceFill é proposital.
         *
         * Não dependemos de $fillable para
         * campos internos calculados pelo
         * próprio Prospector, especialmente
         * fingerprint.
         */
        $evidence->forceFill([
            'company_id' => $company->id,

            'fingerprint' => $fingerprint,

            'dimension' => $dimension,

            'signal' => $signal,

            'source_type' => $sourceType,

            'source_name' => $sourceName,

            'source_url' => $sourceUrl,

            'title' => $title,

            'evidence_text' => $evidenceText,

            'confidence' => $confidence,

            'is_confirmed' => $confirmed,

            'observed_at' => $observedAt,

            'metadata' => $metadata,
        ]);

        $evidence->save();

        /*
         * Toda evidência nova ou atualizada
         * provoca recálculo imediato daquela
         * dimensão.
         */
        $this->scoring
            ->recalculateDimension(
                company: $company,
                dimension: $dimension,
            );

        /*
         * A evidência recalculou uma dimensão
         * de exportação; propagamos a mudança
         * para o Score SDR imediatamente.
         */
        $company->unsetRelation(
            'exportIntelligence'
        );

        $this->sdr->recalculate(
            $company
        );

        return $evidence->refresh();
    }

    private function evidenceFingerprint(
        Company $company,
        string $dimension,
        string $sourceType,
        ?string $sourceUrl,
        ?string $title,
        string $evidenceText,
    ): string {
        $normalizedSourceType =
            $this->normalizeEvidenceText(
                $sourceType
            );

        $normalizedUrl =
            $this->normalizeEvidenceUrl(
                $sourceUrl
            );

        /*
         * Quando existe URL, ela identifica a
         * fonte.
         *
         * O conteúdo pode mudar futuramente
         * sem representar uma evidência nova.
         */
        if ($normalizedUrl !== null) {
            return hash(
                'sha256',
                implode(
                    '|',
                    [
                        (string) $company->id,
                        $dimension,
                        $normalizedSourceType,
                        'url',
                        $normalizedUrl,
                    ]
                )
            );
        }

        /*
         * Quando não existe URL, usamos título
         * + conteúdo normalizados.
         */
        return hash(
            'sha256',
            implode(
                '|',
                [
                    (string) $company->id,
                    $dimension,
                    $normalizedSourceType,
                    'content',

                    $this->normalizeEvidenceText(
                        $title ?? ''
                    ),

                    $this->normalizeEvidenceText(
                        $evidenceText
                    ),
                ]
            )
        );
    }

    private function normalizeEvidenceUrl(
        ?string $url
    ): ?string {
        if ($url === null) {
            return null;
        }

        $url =
            trim(
                $url
            );

        if ($url === '') {
            return null;
        }

        /*
         * Fragmentos não representam uma
         * página diferente.
         */
        $withoutFragment =
            preg_replace(
                '/#.*$/',
                '',
                $url
            );

        if ($withoutFragment !== null) {
            $url =
                $withoutFragment;
        }

        /*
         * Remove barra final:
         *
         * /exportacao
         * /exportacao/
         *
         * são a mesma fonte.
         */
        $url =
            rtrim(
                $url,
                '/'
            );

        return mb_strtolower(
            $url
        );
    }

    private function normalizeEvidenceText(
        string $value
    ): string {
        $value =
            mb_strtolower(
                trim(
                    $value
                )
            );

        $normalized =
            preg_replace(
                '/\s+/u',
                ' ',
                $value
            );

        return $normalized
            ?? $value;
    }

    private function dimensionPrefix(
        string $dimension
    ): string {
        if (
            ! isset(
                self::DIMENSIONS[
                    $dimension
                ]
            )
        ) {
            throw new InvalidArgumentException(
                'Dimensão de exportação inválida: '
                .$dimension
            );
        }

        return self::DIMENSIONS[
            $dimension
        ];
    }

    private function validateConfidence(
        int $confidence
    ): void {
        if (
            $confidence < 0
            || $confidence > 100
        ) {
            throw new InvalidArgumentException(
                'A confiança deve estar '
                .'entre 0 e 100.'
            );
        }
    }
}
