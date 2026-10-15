<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailCampaignSuppression extends Model
{
    protected $fillable = ['normalized_email', 'reason'];
}
