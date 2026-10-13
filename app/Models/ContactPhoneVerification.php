<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactPhoneVerification extends Model
{
    public $timestamps = true;

    protected $fillable = [
        'source_key', 'phone_normalized', 'whatsapp_status',
        'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
