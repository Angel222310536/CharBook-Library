<?php

namespace App\Services;

use App\Models\Author;
use Illuminate\Support\Str;

/**
 * Orquesta búsqueda de metadata combinando Google Books y Open Library.
 *
 * Resiliente a fallos de un proveedor: si Google da 429/error, usa Open Library
 * y viceversa. Solo devuelve null si AMBOS fallan.
 */
class BookMetadataService
{
    public function __construct(
        protected GoogleBooksService $google,
        protected OpenLibraryService $openLibrary,
    ) {}

    public function lookupByIsbn(string $isbn): ?array
    {
        // Ejecuta ambos en silencio; cada uno devuelve array o null
        $google = $this->safe(fn() => $this->google->findByIsbn($isbn));
        $ol = $this->safe(fn() => $this->openLibrary->findByIsbn($isbn));

        if (! $google && ! $ol) return null;
        return $this->merge($google, $ol);
    }

    public function lookupByTitle(string $title, ?string $author = null): ?array
    {
        $google = $this->safe(fn() => $this->google->findByTitle($title, $author));
        $ol = $this->safe(fn() => $this->openLibrary->findByTitle($title, $author));

        if (! $google && ! $ol) return null;
        return $this->merge($google, $ol);
    }

    /** Ejecuta un callback y devuelve null si lanza excepción. */
    private function safe(callable $fn): ?array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Merge inteligente: prefiere datos no-null y elige fuente disponible.
     */
    private function merge(?array $g, ?array $ol): array
    {
        // Helper: primero no vacío
        $pick = fn(string $key) => $g[$key] ?? $ol[$key] ?? null;

        $result = [
            'title' => $pick('title'),
            'subtitle' => $pick('subtitle'),
            'description' => $pick('description'),
            'authors' => $g['authors'] ?? $ol['authors'] ?? [],
            'publisher' => $pick('publisher'),
            'publication_year' => $pick('publication_year'),
            'pages' => $pick('pages'),
            'language' => $this->normalizeLanguage($pick('language')),
            'isbn_10' => $pick('isbn_10'),
            'isbn_13' => $pick('isbn_13'),
            'google_books_id' => $g['google_books_id'] ?? null,
            'open_library_id' => $ol['open_library_id'] ?? null,
            // Cover: prefiere Open Library (covers más grandes); Google como fallback
            'cover_url' => $ol['cover_url'] ?? $g['cover_url'] ?? null,
            // Indica qué fuente devolvió cosas (útil para debug en UI)
            'sources' => array_values(array_filter([
                $g ? 'google_books' : null,
                $ol ? 'open_library' : null,
            ])),
        ];

        // Resuelve author_id (auto-crea si no existe)
        $primaryAuthor = $result['authors'][0] ?? null;
        if ($primaryAuthor) {
            $author = $this->resolveAuthor($primaryAuthor);
            $result['author_id'] = $author->id;
            $result['author_name'] = $author->name;
            $result['author_was_created'] = $author->wasRecentlyCreated;
        }

        return $result;
    }

    private function resolveAuthor(string $name): Author
    {
        $name = trim($name);
        $slug = Str::slug($name);

        $existing = Author::where('slug', $slug)->first();
        if ($existing) return $existing;

        $existing = Author::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        if ($existing) return $existing;

        return Author::create(['name' => $name]);
    }

    private function normalizeLanguage(?string $lang): ?string
    {
        if (! $lang) return null;
        $lang = strtolower(substr($lang, 0, 2));
        return in_array($lang, ['es', 'en', 'fr', 'de', 'pt', 'it', 'ja', 'zh', 'ru']) ? $lang : null;
    }
}
