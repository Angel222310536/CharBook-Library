<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Http\Resources\BookListResource;
use App\Http\Resources\BookResource;
use App\Models\Book;
use App\Services\BookViewService;
use App\Services\FileGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BookController extends Controller
{
    // =========================================================
    // ============ ENDPOINTS PÚBLICOS ============
    // =========================================================

    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('search'));
        $perPage = min((int) $request->input('per_page', 12), 60);

        // ===== Si hay búsqueda → Meilisearch =====
        if ($term !== '') {
            $builder = Book::search($term);

            // Filtros vía Meilisearch (rápidos)
            if ($slug = $request->input('category')) {
                $builder->where('category_slugs', $slug);
            }
            if ($slug = $request->input('author')) {
                $builder->where('author_slug', $slug);
            }
            if ($accessType = $request->input('access_type')) {
                $builder->where('access_type', $accessType);
            }
            if ($lang = $request->input('language')) {
                $builder->where('language', $lang);
            }

            $results = $builder->paginate($perPage)->withQueryString();
            $results->load(['author:id,name,slug']);

            return BookListResource::collection($results)->response();
        }

        // ===== Sin búsqueda → query Eloquent normal =====
        $query = Book::published()->with(['author:id,name,slug']);

        if ($slug = $request->input('category')) {
            $query->whereHas('categories', fn($q) => $q->where('slug', $slug));
        }
        if ($slug = $request->input('author')) {
            $query->whereHas('author', fn($q) => $q->where('slug', $slug));
        }
        if ($accessType = $request->input('access_type')) {
            $query->where('access_type', $accessType);
        }
        if ($lang = $request->input('language')) {
            $query->where('language', $lang);
        }

        match ($request->input('sort', 'recent')) {
            'popular' => $query->orderByDesc('views_count'),
            'rating'  => $query->orderByDesc('rating_average')->orderByDesc('rating_count'),
            'title'   => $query->orderBy('title'),
            default   => $query->orderByDesc('created_at'),
        };

        return BookListResource::collection(
            $query->paginate($perPage)->withQueryString()
        )->response();
    }

    /**
     * GET /api/books/search-instant?q=...
     * Devuelve top resultados (sin paginar) para el dropdown de búsqueda instantánea.
     */
    public function searchInstant(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q'));
        if ($term === '') {
            return response()->json(['data' => []]);
        }

        $books = Book::search($term)
            ->take(8)
            ->get()
            ->load('author:id,name,slug');

        return BookListResource::collection($books)->response();
    }

    public function featured(): JsonResponse
    {
        return BookListResource::collection(
            Book::published()->featured()->with('author:id,name,slug')->limit(12)->get()
        )->response();
    }

    public function recent(): JsonResponse
    {
        return BookListResource::collection(
            Book::published()->with('author:id,name,slug')->recent()->limit(12)->get()
        )->response();
    }

    public function popular(): JsonResponse
    {
        return BookListResource::collection(
            Book::published()->with('author:id,name,slug')->popular()->limit(12)->get()
        )->response();
    }

    public function show(Book $book): JsonResponse
    {
        $this->assertPubliclyReadable($book);

        $book->load(['author', 'categories', 'affiliateLinks', 'creator']);
        return (new BookResource($book))->response();
    }

    public function recordView(Request $request, Book $book, BookViewService $views): JsonResponse
    {
        $this->assertPubliclyReadable($book);

        $data = $request->validate([
            'visitor_key' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $views->record(
                $book,
                $request->user('sanctum'),
                $data['visitor_key'] ?? null,
                $request
            ),
        ]);
    }

    /**
     * GET /api/books/{slug}/file-proxy
     * Stream del archivo (PDF/EPUB), evita CORS para el lector.
     */
    public function fileProxy(Request $request, Book $book)
    {
        if (! $request->hasValidSignature() && ! $request->hasValidSignature(false)) {
            $this->assertPubliclyReadable($book);

            $hasPaidChapters = $book->chapters()
                ->where('is_published', true)
                ->where('coin_price', '>', 0)
                ->exists();

            abort_if(
                $book->access_type !== 'free' || $book->in_unlimited_catalog || $hasPaidChapters,
                403,
                'Usa la ruta segura para acceder a este archivo.'
            );
        }

        $contentType = match ($book->file_type) {
            'pdf'  => 'application/pdf',
            'epub' => 'application/epub+zip',
            default => 'application/octet-stream',
        };
        $filename = "{$book->slug}." . ($book->file_type ?: 'bin');
        $headers = [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=86400',
        ];

        // 1) Archivo en el disco public (local). Es el caso por defecto cuando el
        //    creador sube el libro desde el Studio (MyWorksController@uploadFile):
        //    guarda `file_path` apuntando al disco public — NO es R2. Hay que
        //    comprobar esto ANTES que R2; si no, en dev (sin R2) la lectura da 500.
        $publicDisk = Storage::disk('public');
        $localPath = $book->file_path
            ?: ($book->file_url ? $this->resolveLocalStoragePath($book->file_url) : null);

        if ($localPath !== null && $publicDisk->exists($localPath)) {
            return $publicDisk->download($localPath, $filename, $headers);
        }

        // 2) Archivo en R2 (cloud) — solo si realmente existe allí. Envuelto en
        //    try/catch para que un R2 no configurado (dev) NO rompa la lectura.
        if ($book->file_path) {
            try {
                if (Storage::disk('r2_books')->exists($book->file_path)) {
                    return Storage::disk('r2_books')->download($book->file_path, $filename, $headers);
                }
            } catch (\Throwable $e) {
                \Log::warning('fileProxy R2 no disponible: ' . $e->getMessage());
            }
        }

        if (! $book->file_url) {
            abort(404, 'Archivo no disponible.');
        }

        // 3) URL externa (archive.org, openlibrary, gutenberg…) → stream con cURL
        try {
            $ch = curl_init($book->file_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_USERAGENT => 'CharBookLibrary/1.0',
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false || $status >= 400) {
                return response()->json([
                    'message' => 'No se pudo obtener el archivo del origen externo.',
                    'status' => $status,
                    'error' => $error,
                    'url' => $book->file_url,
                ], 502);
            }
            return response($body, 200, $headers);
        } catch (\Throwable $e) {
            \Log::error('fileProxy externo falló: ' . $e->getMessage());
            return response()->json([
                'message' => 'Error al descargar el libro.',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Si el `file_url` apunta a un archivo local (relativo o absoluto al mismo
     * server), devuelve la ruta relativa dentro del disco `public`.
     */
    private function resolveLocalStoragePath(string $url): ?string
    {
        // Quita el host si la URL apunta a este mismo server
        $appUrl = rtrim(config('app.url', ''), '/');
        $candidates = [$appUrl, 'http://localhost:8000', 'http://127.0.0.1:8000'];
        foreach ($candidates as $base) {
            if ($base && str_starts_with($url, $base)) {
                $path = substr($url, strlen($base));
                if (str_starts_with($path, '/storage/')) {
                    return substr($path, strlen('/storage/'));
                }
            }
        }
        // URL relativa
        if (str_starts_with($url, '/storage/')) {
            return substr($url, strlen('/storage/'));
        }
        return null;
    }

    private function assertPubliclyReadable(Book $book): void
    {
        abort_if(! $book->is_published || $book->is_hidden, 404);
    }

    // =========================================================
    // ============ ENDPOINTS ADMIN ============
    // =========================================================

    /**
     * GET /api/admin/books — listado admin (incluye no publicados, eliminados)
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = Book::query()
            ->with(['author:id,name,slug', 'categories:id,name,slug'])
            ->search($request->input('search'));

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }
        if ($accessType = $request->input('access_type')) {
            $query->where('access_type', $accessType);
        }
        if ($request->has('is_published')) {
            $query->where('is_published', $request->boolean('is_published'));
        }

        $query->orderByDesc('created_at');

        $perPage = min((int) $request->input('per_page', 20), 100);
        return BookListResource::collection(
            $query->paginate($perPage)->withQueryString()
        )->response();
    }

    public function store(StoreBookRequest $request): JsonResponse
    {
        $data = $request->validated();
        $guard = app(FileGuard::class);

        // Upload portada → r2_covers (público). Defensa extra: MIME real + getimagesize.
        if ($request->hasFile('cover')) {
            $guard->validateImage($request->file('cover'), 'cover');
            $path = $request->file('cover')->store('covers', 'r2_covers');
            $data['cover_path'] = $path;
            $data['cover_url'] = null;
        }

        // Upload libro → r2_books (privado, URLs firmadas). Defensa extra: MIME + magic bytes.
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $guard->validateWork($file, 'file');
            $path = $file->store('books', 'r2_books');
            $data['file_path'] = $path;
            $data['file_url'] = null;
            $data['file_size'] = $file->getSize();
        }

        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids'], $data['cover'], $data['file']);

        $book = Book::create($data);
        if (! empty($categoryIds)) {
            $book->categories()->sync($categoryIds);
        }

        $book->load(['author', 'categories']);
        return (new BookResource($book))->response()->setStatusCode(201);
    }

    public function update(UpdateBookRequest $request, Book $book): JsonResponse
    {
        $data = $request->validated();
        $guard = app(FileGuard::class);

        if ($request->hasFile('cover')) {
            $guard->validateImage($request->file('cover'), 'cover');
            if ($book->cover_path) {
                Storage::disk('r2_covers')->delete($book->cover_path);
            }
            $data['cover_path'] = $request->file('cover')->store('covers', 'r2_covers');
            $data['cover_url'] = null;
        }

        if ($request->hasFile('file')) {
            $guard->validateWork($request->file('file'), 'file');
            if ($book->file_path) {
                Storage::disk('r2_books')->delete($book->file_path);
            }
            $file = $request->file('file');
            $data['file_path'] = $file->store('books', 'r2_books');
            $data['file_url'] = null;
            $data['file_size'] = $file->getSize();
        }

        $categoryIds = $data['category_ids'] ?? null;
        unset($data['category_ids'], $data['cover'], $data['file']);

        $book->update($data);
        if ($categoryIds !== null) {
            $book->categories()->sync($categoryIds);
        }

        $book->load(['author', 'categories']);
        return (new BookResource($book))->response();
    }

    public function destroy(Book $book): JsonResponse
    {
        // Soft delete por defecto. Si quieres hard-delete con archivos, descomenta:
        // if ($book->cover_path) Storage::disk('r2_covers')->delete($book->cover_path);
        // if ($book->file_path)  Storage::disk('r2_books')->delete($book->file_path);

        $book->delete();
        return response()->json(['message' => 'Libro eliminado correctamente.']);
    }
}
