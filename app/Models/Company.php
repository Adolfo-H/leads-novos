<?php

namespace App\Models;

use App\Support\TextNormalizer;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = [
        'cnpj_root',
        'corporate_name',
        'normalized_name',
        'legal_nature_code',
        'legal_nature_description',
        'responsible_qualification_code',
        'share_capital',
        'size_code',
        'size_description',
        'federative_entity',
        'source',
        'source_updated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'share_capital' => 'decimal:2',
            'source_updated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Company $company): void {
            $company->uuid ??= (string) Str::uuid();
        });

        static::saving(function (Company $company): void {
            $company->cnpj_root = mb_strtoupper(
                trim($company->cnpj_root)
            );

            $company->normalized_name =
                TextNormalizer::companyName(
                    $company->corporate_name
                );
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return HasMany<Establishment, $this>
     */
    public function establishments(): HasMany
    {
        return $this->hasMany(
            Establishment::class
        );
    }

    /**
     * @return HasOne<CompanyIcpScore, $this>
     */
    public function icpScore(): HasOne
    {
        return $this->hasOne(
            CompanyIcpScore::class
        );
    }

    /**
     * @return HasOne<CompanyCrmCheck, $this>
     */
    public function crmCheck(): HasOne
    {
        return $this->hasOne(
            CompanyCrmCheck::class
        );
    }

    /**
     * @return HasOne<CompanyExportIntelligence, $this>
     */
    public function exportIntelligence(): HasOne
    {
        return $this->hasOne(
            CompanyExportIntelligence::class
        );
    }

    /**
     * @return HasMany<CompanyExportEvidence, $this>
     */
    public function exportEvidence(): HasMany
    {
        return $this->hasMany(
            CompanyExportEvidence::class
        );
    }

    /**
     * @return HasOne<Establishment, $this>
     */
    public function matrix(): HasOne
    {
        return $this->hasOne(
            Establishment::class
        )->where('type', 'matrix');
    }

    /**
     * @return HasOne<CompanySdrScore, $this>
     */
    public function sdrScore(): HasOne
    {
        return $this->hasOne(
            CompanySdrScore::class
        );
    }

    /**
     * @return HasOne<CompanyLeadWorkState, $this>
     */
    public function leadWorkState(): HasOne
    {
        return $this->hasOne(
            CompanyLeadWorkState::class
        );
    }

    /**
     * @return HasOne<CompanyHubSpotLead, $this>
     */
    public function hubSpotLead(): HasOne
    {
        return $this->hasOne(
            CompanyHubSpotLead::class
        );
    }

    /**
     * Registros Company do HubSpot vinculados
     * a esta empresa fiscal.
     *
     * Uma empresa local pode possuir mais de
     * um registro correspondente no CRM.
     *
     * @return HasMany<HubSpotCompany, $this>
     */
    public function hubSpotCompanies(): HasMany
    {
        return $this->hasMany(
            HubSpotCompany::class,
            'company_id'
        );
    }

    /**
     * @return HasMany<CompanyLeadActivity, $this>
     */
    public function leadActivities(): HasMany
    {
        return $this->hasMany(
            CompanyLeadActivity::class
        )
            ->where(
                'is_deleted',
                false
            )
            ->orderByDesc(
                'occurred_at'
            )
            ->orderByDesc(
                'id'
            );
    }
}
