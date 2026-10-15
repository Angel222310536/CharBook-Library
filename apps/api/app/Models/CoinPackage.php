<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoinPackage extends Model
{
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'price_usd' => 'decimal:2'];
}
