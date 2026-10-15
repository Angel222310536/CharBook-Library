<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChapterWaitUnlock extends Model
{
    protected $guarded = [];

    protected $casts = [
        'unlocks_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function book() { return $this->belongsTo(Book::class); }
    public function chapter() { return $this->belongsTo(Chapter::class); }
}
