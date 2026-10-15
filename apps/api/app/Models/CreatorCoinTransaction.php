<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorCoinTransaction extends Model
{
    protected $guarded = [];
    protected $casts = ['meta' => 'array', 'available_at' => 'datetime'];
    public function creator() { return $this->belongsTo(User::class, 'creator_id'); }
}
