<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoinTransaction extends Model
{
    protected $guarded = [];
    protected $casts = ['meta' => 'array'];
    public function user() { return $this->belongsTo(User::class); }
}
