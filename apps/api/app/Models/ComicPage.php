<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ComicPage extends Model
{
    protected $fillable = [
        'book_id', 'chapter_id', 'image_url', 'image_path',
        'position', 'size', 'width', 'height',
    ];

    protected $casts = [
        'chapter_id' => 'integer',
        'position' => 'integer',
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function getImageLinkAttribute(): ?string
    {
        if ($this->image_url) return $this->image_url;
        if ($this->image_path) return Storage::disk('public')->url($this->image_path);
        return null;
    }
}
