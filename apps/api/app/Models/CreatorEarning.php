<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorEarning extends Model
{
    protected $guarded = [];
    protected $casts = ['day' => 'date', 'usd_value' => 'decimal:2'];
}
