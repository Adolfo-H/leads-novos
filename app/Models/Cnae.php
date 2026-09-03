<?php

namespace App\Models;

use Database\Factories\CnaeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Cnae extends Model
{
    /** @use HasFactory<CnaeFactory> */
    use HasFactory;

    protected $fillable = [
        'code',
        'description',
    ];

    /**
     * @return BelongsToMany<Establishment, $this>
     */
    public function establishments(): BelongsToMany
    {
        return $this->belongsToMany(
            Establishment::class
        )
            ->withPivot('is_primary')
            ->withTimestamps();
    }
}
