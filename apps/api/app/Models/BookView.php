<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookView extends Model
{
    protected $fillable = [
        'book_id',
        'user_id',
        'visitor_hash',
        'first_viewed_at',
        'last_viewed_at',
        'sessions_count',
    ];

    protected $casts = [
        'first_viewed_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'sessions_count' => 'integer',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
