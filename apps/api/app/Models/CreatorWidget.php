<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorWidget extends Model
{
    public const TYPE_ANNOUNCEMENT = 'announcement';
    public const TYPE_TIP = 'tip';
    public const TYPE_EVENT = 'event';
    public const TYPE_LINK = 'link';
    public const TYPE_NEW_ARRIVAL = 'new_arrival';
    public const TYPE_FEATURED_CAT = 'featured_cat';

    public const TYPES = [
        self::TYPE_ANNOUNCEMENT,
        self::TYPE_TIP,
        self::TYPE_EVENT,
        self::TYPE_LINK,
        self::TYPE_NEW_ARRIVAL,
        self::TYPE_FEATURED_CAT,
    ];

    protected $fillable = [
        'user_id', 'type', 'title', 'body', 'url',
        'starts_at', 'ends_at',
        'book_id', 'category_id',
        'is_published', 'position',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_published' => 'boolean',
        'position' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
