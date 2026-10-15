<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdReward extends Model
{
    protected $guarded = [];
    protected $casts = ['ssv_payload' => 'array', 'confirmed_at' => 'datetime'];
}
