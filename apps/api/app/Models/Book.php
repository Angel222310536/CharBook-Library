<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Book extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    /**
     * Nombre del índice Meilisearch.
     */
    public function searchableAs(): string
    {
        return 'books';
    }

    /**
     * Solo libros publicados van al índice.
     * Cast explícito a bool por si is_published llega como null o int.
     */
    public function shouldBeSearchable(): bool
    {
        return (bool) $this->is_published;
    }

    /**
     * Datos que se indexan en Meilisearch.
     * Cuidamos de incluir solo lo necesario para búsqueda + ranking.
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['author:id,name,slug', 'categories:id,name,slug']);

        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'author_name' => $this->author?->name,
            'author_slug' => $this->author?->slug,
            'category_names' => $this->categories->pluck('name')->toArray(),
            'category_slugs' => $this->categories->pluck('slug')->toArray(),
            'language' => $this->language,
            'access_type' => $this->access_type,
            'publication_year' => $this->publication_year,
            'isbn_10' => $this->isbn_10,
            'isbn_13' => $this->isbn_13,
            'is_featured' => $this->is_featured,
            // Para ranking
            'views_count' => (int) $this->views_count,
            'total_readings_count' => (int) $this->total_readings_count,
            'rating_average' => (float) $this->rating_average,
            'rating_count' => (int) $this->rating_count,
            'created_at_ts' => $this->created_at?->timestamp,
        ];
    }

    protected $fillable = [
        'title',
        'slug',
        'subtitle',
        'description',
        'synopsis',
        'author_id',
        'creator_id',
        'cover_url',
        'cover_path',
        'file_url',
        'file_path',
        'file_type',
        'file_size',
        'access_type',
        'allow_download',
        'price',
        'isbn_10',
        'isbn_13',
        'google_books_id',
        'open_library_id',
        'publisher',
        'publication_year',
        'language',
        'pages',
        'views_count',
        'total_readings_count',
        'downloads_count',
        'favorites_count',
        'rating_average',
        'rating_count',
        'is_published',
        'is_featured',
        'monetization',
        'in_unlimited_catalog',
        // Campos del flujo de creador (autopublicación)
        'content_type',
        'age_rating',
        'tags',
        'copyright_holder',
        'is_original',
        'submission_status',
        // Moderación y destacados (panel admin)
        'is_hidden',
        'is_sensitive',
        'mod_notes',
        'is_pinned',
        'is_recommended',
        'is_trending',
        'hidden_at',
        'hidden_by',
        // Wizard de creación de obras
        'work_format',
        'parent_category_id',
        'subcategory_id',
        'schedule_at',
        'comments_mode',
        'ratings_enabled',
        'seo_title',
        'seo_description',
        'seo_keywords',
        'author_note_start',
        'author_note_end',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'rating_average' => 'decimal:2',
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'allow_download' => 'boolean',
        'publication_year' => 'integer',
        'pages' => 'integer',
        'file_size' => 'integer',
        'views_count' => 'integer',
        'total_readings_count' => 'integer',
        'downloads_count' => 'integer',
        'favorites_count' => 'integer',
        'rating_count' => 'integer',
        'tags' => 'array',
        'is_original' => 'boolean',
        'is_hidden' => 'boolean',
        'is_sensitive' => 'boolean',
        'is_pinned' => 'boolean',
        'is_recommended' => 'boolean',
        'is_trending' => 'boolean',
        'hidden_at' => 'datetime',
        'schedule_at' => 'datetime',
        'ratings_enabled' => 'boolean',
        'seo_keywords' => 'array',
        'monetization' => 'array',
        'in_unlimited_catalog' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Book $book) {
            if (empty($book->slug)) {
                $base = Str::slug($book->title);
                $slug = $base;
                $i = 1;
                while (static::where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $book->slug = $slug;
            }
        });
    }

    // -------------------- Relaciones --------------------

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    /** User aprobado como creador que subió esta obra (puede ser null). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'book_category');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function favoritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withTimestamps();
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(BookView::class);
    }

    public function affiliateLinks(): HasMany
    {
        return $this->hasMany(AffiliateLink::class)
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id');
    }

    /** Capítulos de la obra (work_format = serie). */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('position');
    }

    /** Páginas de cómic (work_format = comic). */
    public function comicPages(): HasMany
    {
        return $this->hasMany(ComicPage::class)->orderBy('position');
    }

    /** Comentarios estilo WEBTOON a nivel de obra. */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * Sesiones de importación de libros (EPUB/DOCX/TXT → capítulos).
     * Necesaria para el scoped binding de la ruta anidada
     * `GET /me/works/{work}/imports/{import}` — sin esto Laravel lanza
     * "Call to undefined method Book::imports()" y el polling de estado da 500.
     */
    public function imports(): HasMany
    {
        return $this->hasMany(ChapterImport::class);
    }

    // -------------------- Scopes --------------------

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('is_published', true);
    }

    public function scopeFree(Builder $q): Builder
    {
        return $q->where('access_type', 'free');
    }

    public function scopePremium(Builder $q): Builder
    {
        return $q->where('access_type', 'premium');
    }

    public function scopeFeatured(Builder $q): Builder
    {
        return $q->where('is_featured', true);
    }

    public function scopePopular(Builder $q): Builder
    {
        return $q->orderByDesc('views_count');
    }

    public function scopeRecent(Builder $q): Builder
    {
        return $q->orderByDesc('created_at');
    }

    /**
     * Búsqueda LIKE — temporal hasta que esté Meilisearch (Fase 3).
     */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term) {
            return $q;
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return $q->where(function ($w) use ($like) {
            $w->where('title', 'ILIKE', $like) // ILIKE = case-insensitive en Postgres
                ->orWhere('description', 'ILIKE', $like)
                ->orWhere('isbn_10', $like)
                ->orWhere('isbn_13', $like)
                ->orWhereHas('author', fn ($a) => $a->where('name', 'ILIKE', $like));
        });
    }

    // -------------------- Accesores --------------------

    /**
     * URL pública de la portada (URL externa, S3/R2 path, o null).
     */
    public function getCoverAttribute(): ?string
    {
        if ($this->cover_url) {
            return $this->cover_url;
        }
        if ($this->cover_path && config('filesystems.disks.r2_covers')) {
            return Storage::disk('r2_covers')->url($this->cover_path);
        }

        return null;
    }

    /**
     * URL del archivo del libro (firmada si está en R2).
     */
    public function getFileLinkAttribute(): ?string
    {
        if ($this->file_url) {
            return $this->file_url;
        }
        if ($this->file_path && config('filesystems.disks.r2_books')) {
            return Storage::disk('r2_books')->temporaryUrl(
                $this->file_path,
                now()->addMinutes(30)
            );
        }

        return null;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
