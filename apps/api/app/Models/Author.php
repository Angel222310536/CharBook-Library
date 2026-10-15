<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Author extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'biography',
        'photo_url',
        'birth_year',
        'death_year',
        'nationality',
    ];

    protected $casts = [
        'birth_year' => 'integer',
        'death_year' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Author $author) {
            if (empty($author->slug)) {
                $author->slug = Str::slug($author->name);
            }
        });
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
