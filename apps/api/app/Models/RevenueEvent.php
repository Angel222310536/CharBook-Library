<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RevenueEvent extends Model
{
    protected $guarded = [];
    protected $casts = ['meta' => 'array', 'gross_usd' => 'decimal:2', 'platform_usd' => 'decimal:2', 'creator_usd' => 'decimal:2'];
}
