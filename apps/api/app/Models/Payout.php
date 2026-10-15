<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    protected $guarded = [];
    protected $casts = ['amount_usd' => 'decimal:2', 'fee_usd' => 'decimal:2', 'processed_at' => 'datetime'];
    public function creator() { return $this->belongsTo(User::class, 'creator_id'); }
}
