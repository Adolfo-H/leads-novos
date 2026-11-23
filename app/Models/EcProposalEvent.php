<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EcProposalEvent extends Model
{
    public $timestamps = false;

    protected $table = 'ec_proposal_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }
}
