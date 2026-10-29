<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanySdrScore;

final class SdrScoringService
{
    private const VERSION = 'v3';

    /**
     * Cliente não deve voltar para a fila
     * operacional SDR.
     *
     * Oportunidade aberta NÃO é mais bloqueada:
     * ela continua recebendo score e prioridade.
     *
     * @var list<string>
     */
    private const BLOCKED_CRM_STATUSES = [
        'client',
    ];

    public function __construct(
        private readonly LeadQualificationScoreService $qualification,
    ) {}

    /**
     * Mostra um score existente sem regravá-lo a cada visita ao dossiê.
     *
     * A atualização por alteração de ICP, CRM e exportações permanece nos
     * serviços que gravam essas fontes. Como a classificação comercial também
     * depende da passagem do tempo, renovamos o score após um dia.
     *
     * A tela já carrega essas relações; loadMissing não duplica consultas.
     */
    public function currentForDossier(Company $company): CompanySdrScore
    {
        $company->loadMissing([
            'sdrScore',
            'icpScore',
            'crmCheck',
            'exportIntelligence',
        ]);

        $current = $company->sdrScore;
        // O cast datetime retorna Carbon em runtime. A leitura do atributo,
        // porem, pode ser inferida como string|null pelo Larastan.
        // O helper recebe mixed e valida o valor real antes de reutiliza-lo.
        $calculatedTimestamp = self::timestamp($current?->getAttribute('calculated_at'));

        if (
            $current !== null
            && $current->version === self::VERSION
            && $calculatedTimestamp !== null
            && $calculatedTimestamp > now()->subDay()->getTimestamp()
        ) {
            $sourceUpdated = false;

            foreach ([
                $company->icpScore,
                $company->crmCheck,
                $company->exportIntelligence,
            ] as $source) {
                if ($source === null) {
                    continue;
                }

                $updatedAt = $source->getAttribute('updated_at');
                if (
                    $updatedAt instanceof \DateTimeInterface
                    && $updatedAt->getTimestamp() > $calculatedTimestamp
                ) {
                    $sourceUpdated = true;
                    break;
                }
            }

            if (! $sourceUpdated) {
                return $current;
            }
        }

        $score = $this->recalculate($company);
        // Preserva a nova relação no mesmo ciclo Livewire/Blade.
        $company->setRelation('sdrScore', $score);

        return $score;
    }

    private static function timestamp(mixed $value): ?int
    {
        return $value instanceof \DateTimeInterface ? $value->getTimestamp() : null;
    }

    public function recalculate(
        Company $company
    ): CompanySdrScore {
        $company->loadMissing([
            'crmCheck',
        ]);

        $crm =
            $company->crmCheck;

        $crmStatus =
            $this->stringValue(
                $crm?->status
            );

        /*
         * Cliente continua bloqueado para SDR.
         */
        if (
            $crmStatus !== null
            && in_array(
                $crmStatus,
                self::BLOCKED_CRM_STATUSES,
                true
            )
        ) {
            return $this->storeBlocked(
                company: $company,
                crmStatus: $crmStatus,
            );
        }

        $result =
            $this->qualification
                ->calculate(
                    $company
                );

        return CompanySdrScore::query()
            ->updateOrCreate(
                [
                    'company_id' => $company->id,
                ],
                [
                    'score' => $result[
                            'score'
                        ],

                    'priority' => $result[
                            'priority'
                        ],

                    'label' => $result[
                            'label'
                        ],

                    'is_eligible' => true,

                    'is_provisional' => ! $result[
                            'research_complete'
                        ],

                    'blocked_reason' => null,

                    'factors' => $result[
                            'factors'
                        ],

                    'version' => self::VERSION,

                    'metadata' => [
                        'research_complete' => $result[
                                'research_complete'
                            ],

                        'crm_status' => $crmStatus,

                        'commercial_status' => $result[
                                'commercial_status'
                            ],

                        'work_status' => $result[
                                'work_status'
                            ],

                        'icp_raw_score' => $result[
                                'icp_raw_score'
                            ],

                    ],

                    'calculated_at' => now(),
                ]
            );
    }

    /**
     * @param  array<string, mixed>  $extraMetadata
     */
    private function storeBlocked(
        Company $company,
        string $crmStatus,
        ?string $reason = null,
        array $extraMetadata = [],
    ): CompanySdrScore {
        $reason ??=
            match ($crmStatus) {
                'client' => 'Empresa já é cliente',

                'prospected' => 'Empresa prospectada recentemente',

                default => 'Empresa não elegível',
            };

        return CompanySdrScore::query()
            ->updateOrCreate(
                [
                    'company_id' => $company->id,
                ],
                [
                    'score' => 0,

                    'priority' => 'blocked',

                    'label' => 'Não priorizar',

                    'is_eligible' => false,

                    'is_provisional' => false,

                    'blocked_reason' => $reason,

                    'factors' => [],

                    'version' => self::VERSION,

                    'metadata' => array_merge(
                        [
                            'crm_status' => $crmStatus,
                        ],
                        $extraMetadata
                    ),

                    'calculated_at' => now(),
                ]
            );
    }

    private function stringValue(
        mixed $value
    ): ?string {
        return is_string(
            $value
        )
            && trim(
                $value
            ) !== ''
                ? trim(
                    $value
                )
                : null;
    }
}
