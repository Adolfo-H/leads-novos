<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EcProposal extends Model
{
    protected $table = 'ec_proposals';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'service_codes' => 'array', 'proposal_numbers' => 'array', 'accepted_services' => 'array',
            'price_snapshot' => 'array', 'issued_on' => 'date:Y-m-d',
            'price_date' => 'date:Y-m-d', 'issued_at' => 'datetime',
            'recurring_from' => 'date:Y-m-d', 'recurring_to' => 'date:Y-m-d',
            'retro_from' => 'date:Y-m-d', 'retro_to' => 'date:Y-m-d',
            'accepted_at' => 'datetime', 'monthly_final' => 'decimal:2',
            'one_time_final' => 'decimal:2',
        ];
    }

    /** @return HasMany<EcProposalDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EcProposalDocument::class, 'proposal_id');
    }

    /** @return HasMany<EcProposalEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(EcProposalEvent::class, 'proposal_id')->orderByDesc('id');
    }
}
