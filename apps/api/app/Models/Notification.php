<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    public const CATEGORIES = [
        'reading', 'comments', 'followers', 'works', 'system', 'monetization',
    ];

    protected $fillable = [
        'user_id', 'category', 'type', 'emoji', 'title', 'body', 'link', 'data', 'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
