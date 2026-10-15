<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $guarded = [];
    protected $casts = ['current_period_end' => 'datetime', 'cancel_at_period_end' => 'boolean'];
    public function user() { return $this->belongsTo(User::class); }
}
