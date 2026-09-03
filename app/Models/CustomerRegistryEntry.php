<?php

namespace App\Models;

use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Model;

class CustomerRegistryEntry extends Model
{
    protected $fillable = [
        'cnpj_root',
        'cnpj',
        'corporate_name',
        'normalized_name',
        'source',
        'enabled',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(
            function (
                CustomerRegistryEntry $entry
            ): void {
                $entry->normalized_name =
                    TextNormalizer::companyName(
                        $entry->corporate_name
                    ) ?? '';
            }
        );
    }
}
