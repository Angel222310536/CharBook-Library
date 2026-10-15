<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorWallet extends Model
{
    protected $guarded = [];
    protected $casts = ['payouts_enabled' => 'boolean'];
    public function user() { return $this->belongsTo(User::class); }
}
