<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'book_id',
        'meta',
        'likes_count',
        'comments_count',
        'shares_count',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /**
     * Registra una actividad evitando duplicar la misma (usuario, tipo, libro).
     * Si ya existe, refresca su recencia para que vuelva a subir en el feed.
     */
    public static function log(int $userId, string $type, ?int $bookId = null, ?array $meta = null): self
    {
        $activity = static::firstOrNew([
            'user_id' => $userId,
            'type' => $type,
            'book_id' => $bookId,
        ]);

        $activity->meta = $meta;
        // Bubble up: si ya existía, lo tratamos como evento reciente.
        $activity->created_at = now();
        $activity->save();

        return $activity;
    }

    // -------------------- Relaciones --------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ActivityComment::class);
    }

    public function likedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'activity_likes')->withTimestamps();
    }

    public function sharedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'activity_shares')->withTimestamps();
    }
}
