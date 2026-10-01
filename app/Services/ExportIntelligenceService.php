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
            $this
                ->evidenceFingerprint(
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
         * nova pesquisa atualiza o mesmo
         * registro, evitando duplicidade.
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
         * REGRA DE PROTEÇÃO HUMANA
         * ------------------------
         *
         * Se uma pessoa já confirmou essa
         * evidência, uma coleta automática
         * posterior NÃO pode:
         *
         * - remover a confirmação;
         * - inverter positivo/negativo;
         * - diminuir confiança;
         * - trocar o snapshot textual que
         *   justificou a confirmação.
         *
         * A observação automática nova fica
         * guardada separadamente no metadata.
         *
         * Uma chamada confirmed=true continua
         * podendo representar uma nova revisão
         * humana consciente.
         */
        $preserveConfirmedReview =
            $evidence->exists
            && (bool)
                $evidence
                    ->is_confirmed
            && ! $confirmed;

        /**
         * O model possui cast "array" para metadata.
         *
         * getAttribute() executa esse cast em runtime.
         * A anotação explicita ao PHPStan o tipo já
         * convertido pelo Eloquent.
         *
         * @var array<string, mixed>|null $existingMetadata
         */
        $existingMetadata =
            $evidence->getAttribute(
                'metadata'
            );

        $existingMetadataArray =
            $existingMetadata
            ?? [];

        if (
            $preserveConfirmedReview
        ) {
            /*
             * Não misturamos metadata automático
             * no nível principal para não
             * sobrescrever informações de uma
             * eventual revisão humana.
             */
            $storedMetadata =
                $existingMetadataArray;

            $automaticObservedAt =
                $observedAt
                ?? now();

            $storedMetadata[
                'latest_automatic_observation'
            ] = [
                'signal' => $signal,

                'confidence' => $confidence,

                'source_type' => $sourceType,

                'source_name' => $sourceName,

                'source_url' => $sourceUrl,

                'title' => $title,

                'evidence_text' => $evidenceText,

                'observed_at' => $automaticObservedAt
                    ->format(
                        DATE_ATOM
                    ),

                'metadata' => $metadata,
            ];
        } else {
            /*
             * Evidência ainda não confirmada:
             * atualização automática normal.
             */
            $storedMetadata =
                array_merge(
                    $existingMetadataArray,
                    $metadata,
                );
        }

        $signalToStore =
            $preserveConfirmedReview
                ? (string)
                    $evidence
                        ->signal
                : $signal;

        $confidenceToStore =
            $preserveConfirmedReview
                ? (int)
                    $evidence
                        ->confidence
                : $confidence;

        $confirmedToStore =
            $preserveConfirmedReview
                ? true
                : $confirmed;

        /*
         * Preservamos também o snapshot da
         * fonte que havia sido confirmado.
         */
        $sourceNameToStore =
            $preserveConfirmedReview
                ? $evidence
                    ->source_name
                : $sourceName;

        $sourceUrlToStore =
            $preserveConfirmedReview
                ? $evidence
                    ->source_url
                : $sourceUrl;

        $titleToStore =
            $preserveConfirmedReview
                ? $evidence
                    ->title
                : $title;

        $evidenceTextToStore =
            $preserveConfirmedReview
                ? (string)
                    $evidence
                        ->evidence_text
                : $evidenceText;

        $observedAtToStore =
            $preserveConfirmedReview
                ? $evidence
                    ->observed_at
                : $observedAt;

        /*
         * forceFill é proposital.
         *
         * Não dependemos de $fillable para
         * campos internos calculados pelo
         * próprio Prospector.
         */
        $evidence->forceFill([
            'company_id' => $company->id,

            'fingerprint' => $fingerprint,

            'dimension' => $dimension,

            'signal' => $signalToStore,

            'source_type' => $sourceType,

            'source_name' => $sourceNameToStore,

            'source_url' => $sourceUrlToStore,

            'title' => $titleToStore,

            'evidence_text' => $evidenceTextToStore,

            'confidence' => $confidenceToStore,

            'is_confirmed' => $confirmedToStore,

            'observed_at' => $observedAtToStore,

            'metadata' => $storedMetadata,
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
         * para o Score SDR.
         */
        $company
            ->unsetRelation(
                'exportIntelligence'
            );

        $this->sdr
            ->recalculate(
                $company
            );

        return $evidence
            ->refresh();
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
