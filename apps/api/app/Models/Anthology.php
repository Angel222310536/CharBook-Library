<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Anthology extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'slug',
        'description',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    // Resolver rutas por slug
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::creating(function (Anthology $anthology) {
            if (empty($anthology->slug)) {
                $anthology->slug = static::uniqueSlug($anthology->title);
            }
        });
    }

    /** Genera un slug único global a partir del título. */
    public static function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'antologia';
        $slug = $base;
        $i = 1;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    // -------------------- Relaciones --------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'anthology_book')
            ->withPivot(['position', 'note'])
            ->withTimestamps()
            ->orderBy('anthology_book.position');
    }
}
