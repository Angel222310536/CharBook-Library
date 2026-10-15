<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente para Google Books API.
 * Sin API key (free tier permite suficiente para uso interno).
 *
 * Docs: https://developers.google.com/books/docs/v1/using
 */
class GoogleBooksService
{
    private const BASE = 'https://www.googleapis.com/books/v1';

    /**
     * Busca por ISBN (10 o 13). Devuelve null si no encuentra.
     */
    public function findByIsbn(string $isbn): ?array
    {
        $isbn = preg_replace('/[^0-9X]/i', '', $isbn);
        if (! $isbn) return null;

        try {
            $params = ['q' => "isbn:{$isbn}", 'maxResults' => 1];
            if ($key = config('services.google_books.key')) {
                $params['key'] = $key;
            }

            $res = Http::timeout(8)->get(self::BASE . '/volumes', $params);

            if ($res->status() === 429) {
                Log::info('GoogleBooks rate-limited (429). Cayendo a Open Library.');
                return null;
            }
            if (! $res->ok()) return null;

            $item = $res->json('items.0');
            return $item ? $this->normalize($item) : null;
        } catch (\Throwable $e) {
            Log::warning('GoogleBooks ISBN lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Busca por título + autor opcional. Devuelve el mejor match o null.
     */
    public function findByTitle(string $title, ?string $author = null): ?array
    {
        $q = 'intitle:' . $this->quoted($title);
        if ($author) $q .= '+inauthor:' . $this->quoted($author);

        try {
            $res = Http::timeout(8)
                ->get(self::BASE . '/volumes', ['q' => $q, 'maxResults' => 1, 'langRestrict' => null]);

            if (! $res->ok()) return null;
            $item = $res->json('items.0');
            return $item ? $this->normalize($item) : null;
        } catch (\Throwable $e) {
            Log::warning('GoogleBooks title lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    private function normalize(array $item): array
    {
        $v = $item['volumeInfo'] ?? [];
        $industryIds = collect($v['industryIdentifiers'] ?? [])
            ->keyBy('type')
            ->map(fn($x) => $x['identifier'])
            ->toArray();

        // Mejor cover disponible (thumbnail por defecto es ~128px; quitar el zoom param para más resolución)
        $cover = $v['imageLinks']['extraLarge']
            ?? $v['imageLinks']['large']
            ?? $v['imageLinks']['medium']
            ?? $v['imageLinks']['thumbnail']
            ?? null;
        // HTTPS y sin zoom (Google permite manipular)
        if ($cover) {
            $cover = preg_replace('/^http:/', 'https:', $cover);
            $cover = preg_replace('/&zoom=\d+/', '', $cover);
        }

        return [
            'source' => 'google_books',
            'google_books_id' => $item['id'] ?? null,
            'title' => $v['title'] ?? null,
            'subtitle' => $v['subtitle'] ?? null,
            'authors' => $v['authors'] ?? [],
            'description' => $v['description'] ?? null,
            'publisher' => $v['publisher'] ?? null,
            'publication_year' => isset($v['publishedDate'])
                ? (int) substr($v['publishedDate'], 0, 4)
                : null,
            'pages' => $v['pageCount'] ?? null,
            'language' => $v['language'] ?? null,
            'categories' => $v['categories'] ?? [],
            'isbn_10' => $industryIds['ISBN_10'] ?? null,
            'isbn_13' => $industryIds['ISBN_13'] ?? null,
            'cover_url' => $cover,
            'preview_link' => $v['previewLink'] ?? null,
        ];
    }

    private function quoted(string $s): string
    {
        return '"' . str_replace('"', '', $s) . '"';
    }
}
