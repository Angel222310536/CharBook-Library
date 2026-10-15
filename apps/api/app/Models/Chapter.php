<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Chapter extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'book_id', 'number', 'title', 'body',
        'file_url', 'file_path', 'file_type', 'file_size',
        'cover_path', 'cover_url',
        'author_note',
        'position', 'schedule_at', 'is_published',
        'coin_price', 'early_access_until',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'schedule_at' => 'datetime',
        'number' => 'integer',
        'position' => 'integer',
        'file_size' => 'integer',
        'coin_price' => 'integer',
        'early_access_until' => 'datetime',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** Páginas de cómic del capítulo (webtoon), en orden de lectura. */
    public function comicPages(): HasMany
    {
        return $this->hasMany(ComicPage::class)->orderBy('position');
    }

    /** Comentarios estilo WEBTOON por episodio/capítulo. */
    public function comments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** URL pública del archivo del capítulo. */
    public function getFileLinkAttribute(): ?string
    {
        if ($this->file_url) return $this->file_url;
        if ($this->file_path && Storage::disk('public')->exists($this->file_path)) {
            return Storage::disk('public')->url($this->file_path);
        }
        return null;
    }

    /** URL pública de la portada (miniatura) del capítulo. */
    public function getCoverLinkAttribute(): ?string
    {
        if ($this->cover_url) return $this->cover_url;
        if ($this->cover_path && Storage::disk('public')->exists($this->cover_path)) {
            return Storage::disk('public')->url($this->cover_path);
        }
        return null;
    }
}
