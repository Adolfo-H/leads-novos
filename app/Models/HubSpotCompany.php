<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HubSpotCompany extends Model
{
    /**
     * Fontes que NÃO provam identidade fiscal.
     *
     * Uma associação entre duas Companies através
     * de Deal, Contact ou Activity não significa
     * que elas possuem o mesmo CNPJ.
     *
     * @var list<string>
     */
    public const UNSAFE_FISCAL_MATCH_SOURCES = [
        'hubspot_related_explicit_cnpj',

        /*
         * Domínio sozinho também não comprova
         * identidade fiscal.
         */
        'existing_company_unique_domain',
    ];

    protected $table =
        'hubspot_companies';

    protected $fillable = [
        'hubspot_import_run_id',
        'company_id',
        'matched_cnpj_root',
        'matched_company_name',
        'match_source',
        'hubspot_id',
        'name',
        'domain',
        'lifecycle_stage',
        'lead_status',
        'owner_name',
        'city',
        'state',
        'phone',
        'last_activity_at',
        'hubspot_created_at',
        'hubspot_updated_at',
        'raw_properties',
    ];

    protected static function booted(): void
    {
        static::saving(
            function (
                HubSpotCompany $company
            ): void {
                /*
                 * Impede que o erro histórico volte:
                 *
                 * Company A associada a Deal associado
                 * também à Company B NÃO pode herdar o
                 * CNPJ da Company B.
                 */
                if (
                    self::isUnsafeFiscalMatchSource(
                        $company->match_source
                    )
                    && (
                        $company->isDirty(
                            'match_source'
                        )
                        || $company->isDirty(
                            'company_id'
                        )
                        || $company->isDirty(
                            'matched_cnpj_root'
                        )
                    )
                ) {
                    throw new DomainException(
                        'Vínculo fiscal recusado: '
                        .'associação HubSpot não pode '
                        .'propagar CNPJ entre empresas.'
                    );
                }
            }
        );
    }

    public static function isUnsafeFiscalMatchSource(
        mixed $source
    ): bool {
        $source =
            mb_strtolower(
                trim(
                    (string) $source
                )
            );

        if ($source === '') {
            return false;
        }

        if (
            in_array(
                $source,
                self::UNSAFE_FISCAL_MATCH_SOURCES,
                true
            )
        ) {
            return true;
        }

        /*
         * Defesa contra a mesma lógica reaparecer
         * futuramente com outro nome.
         */
        return
            str_starts_with(
                $source,
                'hubspot_related_'
            )
            || str_contains(
                $source,
                'inherited_cnpj'
            )
            || str_contains(
                $source,
                'propagated_cnpj'
            );
    }

    /**
     * @param  Builder<HubSpotCompany>  $query
     * @return Builder<HubSpotCompany>
     */
    public function scopeTrustedFiscalLink(
        Builder $query
    ): Builder {
        return $query->where(
            function (
                Builder $query
            ): void {
                $query
                    ->whereNull(
                        'match_source'
                    )
                    ->orWhereNotIn(
                        'match_source',
                        self::UNSAFE_FISCAL_MATCH_SOURCES
                    );
            }
        );
    }

    public function hasTrustedFiscalLink(): bool
    {
        return
            $this->company_id !== null
            && ! self::isUnsafeFiscalMatchSource(
                $this->match_source
            );
    }

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',

            'hubspot_created_at' => 'datetime',

            'hubspot_updated_at' => 'datetime',

            'raw_properties' => 'array',
        ];
    }

    /**
     * @return BelongsTo<HubSpotImportRun, $this>
     */
    public function importRun(): BelongsTo
    {
        return $this->belongsTo(
            HubSpotImportRun::class,
            'hubspot_import_run_id'
        );
    }

    /**
     * Empresa identificada na base Receita /
     * Prospector.
     *
     * @return BelongsTo<Company, $this>
     */
    public function prospectorCompany(): BelongsTo
    {
        return $this->belongsTo(
            Company::class,
            'company_id'
        );
    }

    /**
     * @return BelongsToMany<HubSpotDeal, $this>
     */
    public function deals(): BelongsToMany
    {
        return $this->belongsToMany(
            HubSpotDeal::class,
            'hubspot_company_deal',
            'hubspot_company_id',
            'hubspot_deal_id',
        )
            ->withPivot(
                'is_primary'
            )
            ->withTimestamps();
    }

    /**
     * Negócios que pertencem comercialmente
     * a esta Company.
     *
     * - Primary Company;
     * - ou Deal com somente uma Company.
     *
     * @return BelongsToMany<HubSpotDeal, $this>
     */
    public function commercialDeals(): BelongsToMany
    {
        return $this
            ->deals()
            ->where(
                function (
                    $query
                ): void {
                    $query
                        ->where(
                            'hubspot_company_deal.is_primary',
                            true
                        )
                        ->orWhereRaw(
                            '(SELECT COUNT(*) '
                            .'FROM hubspot_company_deal hcd_count '
                            .'WHERE hcd_count.hubspot_deal_id = '
                            .'hubspot_company_deal.hubspot_deal_id) = 1'
                        );
                }
            );
    }

    /**
     * @return BelongsToMany<HubSpotContact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(
            HubSpotContact::class,
            'hubspot_company_contact',
            'hubspot_company_id',
            'hubspot_contact_id',
        )
            ->withPivot(
                'is_primary'
            )
            ->withTimestamps();
    }

    /**
     * Atividades comerciais preservadas mesmo
     * antes da identificação fiscal.
     *
     * @return BelongsToMany<HubSpotActivity, $this>
     */
    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(
            HubSpotActivity::class,
            'hubspot_activity_company',
            'hubspot_company_id',
            'hubspot_activity_id',
        )
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<HubSpotTask, $this>
     */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(
            HubSpotTask::class,
            'hubspot_company_task',
            'hubspot_company_id',
            'hubspot_task_id',
        )
            ->withTimestamps();
    }
}
