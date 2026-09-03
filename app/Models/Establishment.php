<?php

namespace App\Models;

use Database\Factories\EstablishmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Establishment extends Model
{
    /** @use HasFactory<EstablishmentFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'cnpj',
        'order_number',
        'check_digits',
        'type',
        'fantasy_name',

        'registration_status_code',
        'registration_status',
        'registration_status_date',
        'registration_status_reason_code',

        'start_date',

        'foreign_city_name',
        'country_code',

        'address_type',
        'street',
        'number',
        'complement',
        'neighborhood',
        'zip_code',
        'state',
        'municipality_code',
        'municipality_name',

        'phone_1',
        'phone_2',
        'fax',
        'email',

        'special_situation',
        'special_situation_date',

        'source',
        'source_updated_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'registration_status_date' => 'date',
            'start_date' => 'date',
            'special_situation_date' => 'date',
            'source_updated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (
            Establishment $establishment
        ): void {
            $establishment->uuid ??=
                (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(
            Company::class
        );
    }

    /**
     * @return BelongsToMany<Cnae, $this>
     */
    public function cnaes(): BelongsToMany
    {
        return $this->belongsToMany(
            Cnae::class
        )
            ->withPivot('is_primary')
            ->withTimestamps();
    }
}
