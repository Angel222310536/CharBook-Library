<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorFundDistribution extends Model
{
    protected $guarded = [];
    protected $casts = ['breakdown' => 'array', 'pool_usd' => 'decimal:2'];
}
