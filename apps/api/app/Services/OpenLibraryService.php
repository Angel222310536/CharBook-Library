<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente para Open Library API.
 * Sin auth requerida. Buena fuente cuando Google Books no tiene cover decente.
 *
 * Docs: https://openlibrary.org/developers/api
 */
class OpenLibraryService
{
    private const BASE = 'https://openlibrary.org';
    private const COVERS = 'https://covers.openlibrary.org';

    public function findByIsbn(string $isbn): ?array
    {
        $isbn = preg_replace('/[^0-9X]/i', '', $isbn);
        if (! $isbn) return null;

        try {
            $res = Http::timeout(8)
                ->get(self::BASE . '/api/books', [
                    'bibkeys' => "ISBN:{$isbn}",
                    'format' => 'json',
                    'jscmd' => 'data',
                ]);

            if (! $res->ok()) return null;
            $data = $res->json("ISBN:{$isbn}");
            if (! $data) return null;

            return $this->normalize($data, $isbn);
        } catch (\Throwable $e) {
            Log::warning('OpenLibrary ISBN lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    public function findByTitle(string $title, ?string $author = null): ?array
    {
        try {
            $params = ['title' => $title, 'limit' => 1];
            if ($author) $params['author'] = $author;

            $res = Http::timeout(8)->get(self::BASE . '/search.json', $params);
            if (! $res->ok()) return null;
            $doc = $res->json('docs.0');
            if (! $doc) return null;

            $isbn = $doc['isbn'][0] ?? null;
            // Reintenta con detalles si hay ISBN
            if ($isbn) return $this->findByIsbn($isbn);

            return [
                'source' => 'open_library',
                'open_library_id' => $doc['key'] ?? null,
                'title' => $doc['title'] ?? null,
                'subtitle' => null,
                'authors' => $doc['author_name'] ?? [],
                'description' => null,
                'publisher' => $doc['publisher'][0] ?? null,
                'publication_year' => $doc['first_publish_year'] ?? null,
                'pages' => $doc['number_of_pages_median'] ?? null,
                'language' => $doc['language'][0] ?? null,
                'isbn_10' => null,
                'isbn_13' => null,
                'cover_url' => isset($doc['cover_i'])
                    ? self::COVERS . "/b/id/{$doc['cover_i']}-L.jpg"
                    : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('OpenLibrary title lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    private function normalize(array $d, string $isbn): array
    {
        return [
            'source' => 'open_library',
            'open_library_id' => $d['key'] ?? null,
            'title' => $d['title'] ?? null,
            'subtitle' => $d['subtitle'] ?? null,
            'authors' => collect($d['authors'] ?? [])->pluck('name')->toArray(),
            'description' => is_array($d['notes'] ?? null) ? ($d['notes']['value'] ?? null) : ($d['notes'] ?? null),
            'publisher' => $d['publishers'][0]['name'] ?? null,
            'publication_year' => isset($d['publish_date'])
                ? (int) preg_replace('/\D/', '', substr($d['publish_date'], -4))
                : null,
            'pages' => $d['number_of_pages'] ?? null,
            'language' => null,
            'isbn_10' => strlen($isbn) === 10 ? $isbn : null,
            'isbn_13' => strlen($isbn) === 13 ? $isbn : null,
            'cover_url' => $d['cover']['large']
                ?? $d['cover']['medium']
                ?? (isset($d['cover']['small']) ? $d['cover']['small'] : self::COVERS . "/b/isbn/{$isbn}-L.jpg"),
        ];
    }
}
