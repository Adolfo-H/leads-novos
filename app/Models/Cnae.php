<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Cnae extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'description',
    ];

    public function establishments(): BelongsToMany
    {
        return $this->belongsToMany(
            Establishment::class
        )
            ->withPivot('is_primary')
            ->withTimestamps();
    }
}
