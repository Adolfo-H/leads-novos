<?php

use App\Models\Company;
use App\Services\ExportIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it(
    'recalculates stored export scoring without changing research freshness',
    function () {
        $company =
            Company::query()->create([
                'cnpj_root' => '99887766',

                'corporate_name' => 'Empresa Backfill Exportação',
            ]);

        $service = app(
            ExportIntelligenceService::class
        );

        /*
         * Simula os cinco resultados neutros
         * que antes faziam a confiança chegar
         * artificialmente a 95%.
         */
        foreach (
            range(
                1,
                5
            ) as $index
        ) {
            $service->recordEvidence(
                company: $company,
                dimension: 'direct',
                signal: 'neutral',
                sourceType: 'other',
                evidenceText: 'Resultado neutro '
                    .$index
                    .'.',
                confidence: 55,
            );
        }

        $intelligence =
            $company
                ->exportIntelligence()
                ->firstOrFail();

        /*
         * Forçamos o estado legado para
         * reproduzir uma empresa calculada
         * com a regra antiga.
         */
        $researchedAt =
            now()
                ->subDays(45)
                ->startOfSecond();

        $metadata =
            is_array(
                $intelligence->metadata
            )
                ? $intelligence->metadata
                : [];

        $metadata[
            'research_summary'
        ] = [
            'headline' => 'Resumo antigo.',

            'dimensions' => [
                'direct' => [
                    'confidence' => 95,
                ],
            ],
        ];

        $intelligence->update([
            'direct_status' => 'uncertain',

            'direct_confidence' => 95,

            'researched_at' => $researchedAt,

            'metadata' => $metadata,
        ]);
        $intelligence->refresh();

        $researchedAtBefore =
            $intelligence
                ->researched_at
                ?->toDateTimeString();

        $this
            ->artisan(

                'exports:recalculate-scoring',
                [
                    '--company-id' => (string) $company->id,
                ]
            )
            ->assertSuccessful();

        $intelligence->refresh();

        expect(
            $intelligence
                ->direct_status
        )->toBe(
            'uncertain'
        );

        expect(
            $intelligence
                ->direct_confidence
        )->toBe(
            55
        );

        /*
         * O backfill não representa uma nova
         * pesquisa. A data original deve ser
         * preservada.
         */
        expect(
            $researchedAtBefore
        )->not->toBeNull();

        expect(
            $intelligence
                ->researched_at
                ?->toDateTimeString()
        )->toBe(
            $researchedAtBefore
        );

        expect(
            data_get(
                $intelligence->metadata,
                'scoring.direct.version'
            )
        )->toBe(
            'v2'
        );

        /*
         * O resumo armazenado também precisa
         * refletir o novo scoring.
         */
        expect(
            data_get(
                $intelligence->metadata,
                'research_summary.dimensions.direct.confidence'
            )
        )->toBe(
            55
        );

        $sdr =
            $company
                ->sdrScore()
                ->firstOrFail();

        $directFactor =
            collect(
                $sdr->factors
            )->firstWhere(
                'key',
                'direct'
            );

        expect(
            $directFactor[
                'detail'
            ]
        )->toBe(
            'Sem comprovação suficiente'
        );
    }
);
