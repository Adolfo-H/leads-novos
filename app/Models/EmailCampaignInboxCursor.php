<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaignInboxCursor extends Model
{
    protected $fillable = ['mailbox', 'uid_validity', 'last_uid', 'last_synced_at'];

    protected function casts(): array
    {
        return ['last_uid' => 'integer', 'last_synced_at' => 'datetime'];
    }
}
