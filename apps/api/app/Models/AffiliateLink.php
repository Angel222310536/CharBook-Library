<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AffiliateLink extends Model
{
    protected $fillable = [
        'book_id',
        'vendor',
        'url',
        'label',
        'position',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'position' => 'integer',
        'click_count' => 'integer',
    ];

    /** Vendors válidos. */
    public const VENDORS = [
        'amazon',
        'kobo',
        'audible',
        'apple_books',
        'google_play',
        'barnes_noble',
        'other',
    ];

    /** Etiqueta legible para UI. */
    public const VENDOR_LABELS = [
        'amazon' => 'Amazon',
        'kobo' => 'Kobo',
        'audible' => 'Audible',
        'apple_books' => 'Apple Books',
        'google_play' => 'Google Play Books',
        'barnes_noble' => 'Barnes & Noble',
        'other' => 'Otro',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class);
    }
}
