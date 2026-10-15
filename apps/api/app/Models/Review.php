<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = ['user_id', 'book_id', 'rating', 'title', 'comment'];

    protected $casts = [
        'rating' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * Recalcula y persiste rating_average + rating_count en el Book.
     * Llamado por boot() del modelo cuando se crea/actualiza/borra una review.
     */
    public static function recalcBookRating(int $bookId): void
    {
        $stats = static::where('book_id', $bookId)
            ->selectRaw('AVG(rating) as avg, COUNT(*) as cnt')
            ->first();

        Book::where('id', $bookId)->update([
            'rating_average' => round((float) ($stats->avg ?? 0), 2),
            'rating_count' => (int) ($stats->cnt ?? 0),
        ]);
    }

    protected static function booted(): void
    {
        static::saved(fn(Review $r) => static::recalcBookRating($r->book_id));
        static::deleted(fn(Review $r) => static::recalcBookRating($r->book_id));
    }
}
