<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReadingEvent extends Model
{
    protected $guarded = [];
    protected $casts = ['is_valid' => 'boolean', 'day' => 'date'];
}
