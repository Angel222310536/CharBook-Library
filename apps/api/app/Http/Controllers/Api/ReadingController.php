<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Chapter;
use App\Services\Export\BookEpubExporter;
use App\Services\Import\ImportManager;
use App\Services\PlatformSettings;
use App\Services\UnlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints públicos de lectura de obras.
 * Devuelven solo contenido publicado (is_published = true).
 * Los capítulos de pago se sirven solo si el espectador tiene derecho
 * (gratis / desbloqueado / suscripción) — si no, 402 con datos del paywall.
 */
class ReadingController extends Controller
{
    public function __construct(private readonly UnlockService $unlocks) {}

    /**
     * GET /api/books/{book:slug}/chapters
     * Lista capítulos publicados (sin body completo, solo metadata).
     */
    public function chapters(Book $book): JsonResponse
    {
        $this->assertPubliclyReadable($book);

        $chapters = $book->chapters()
            ->where('is_published', true)
            ->orderBy('position')
            ->get(['id', 'number', 'title', 'author_note', 'file_url', 'file_type', 'cover_path', 'cover_url', 'position', 'created_at']);

        return response()->json([
            'data' => $chapters->map(fn ($c) => [
                'id' => $c->id,
                'number' => (int) $c->number,
                'title' => $c->title,
                'has_body' => true,
                'has_file' => (bool) $c->file_url,
                'file_type' => $c->file_type,
                'cover_url' => $c->cover_link,
                'position' => (int) $c->position,
                'published_at' => $c->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * GET /api/books/{book:slug}/chapters/{number}
     * Devuelve el contenido completo de un capítulo por su número.
     */
    public function chapter(Request $request, Book $book, int $number): JsonResponse
    {
        $this->assertPubliclyReadable($book);

        $chapter = $book->chapters()
            ->where('is_published', true)
            ->where('number', $number)
            ->first();

        if (! $chapter) {
            return response()->json(['message' => 'Capítulo no encontrado'], 404);
        }

        // ===== PAYWALL (server-side, nunca confiar en el cliente) =====
        $viewer = $request->user('sanctum');
        $access = $this->unlocks->chapterAccess($viewer, $book, $chapter);

        if (! $access['access']) {
            return response()->json([
                'message' => $access['reason'] === 'early_access'
                    ? 'Este capítulo está en acceso anticipado.'
                    : 'Este capítulo es de pago.',
                'paywall' => [
                    'reason' => $access['reason'],
                    'coin_price' => $access['price'],
                    'early_access' => $access['early_access'],
                    'packs' => data_get($book->monetization, 'packs', [3, 5, 10]),
                    'pack_discount_pct' => data_get($book->monetization, 'pack_discount_pct', new \stdClass),
                    'premium_unlock_discount_pct' => PlatformSettings::get('subs.premium_unlock_discount_pct'),
                    'options' => ['premium', 'premium_plus', 'coins', 'ad_reward'],
                ],
                'chapter' => [
                    'id' => $chapter->id,
                    'number' => (int) $chapter->number,
                    'title' => $chapter->title,
                ],
            ], 402);
        }

        // Capítulos adyacentes para navegación
        $prev = $book->chapters()
            ->where('is_published', true)
            ->where('number', '<', $number)
            ->orderByDesc('number')
            ->first(['id', 'number', 'title']);

        $next = $book->chapters()
            ->where('is_published', true)
            ->where('number', '>', $number)
            ->orderBy('number')
            ->first(['id', 'number', 'title']);

        // Lectura inline sin descargas: si el capítulo trae un TXT/DOCX/EPUB
        // adjunto y aún no tiene texto, lo extraemos (y cacheamos) para que se
        // lea en la plataforma. El PDF/imagen lo renderiza el lector (pdf.js).
        $body = $this->readableBody($chapter);

        return response()->json([
            'data' => [
                'id' => $chapter->id,
                'number' => (int) $chapter->number,
                'title' => $chapter->title,
                'body' => $body,
                'author_note' => $chapter->author_note,
                // El PDF del capítulo se sirve por el file-proxy del API (streaming
                // server-side desde R2/public con CORS *), no la URL cruda de R2.
                'file_url' => strtolower((string) $chapter->file_type) === 'pdf'
                    ? url("/api/chapters/{$chapter->id}/file-proxy")
                    : $chapter->file_link,
                'file_type' => $chapter->file_type,
                'file_size' => $chapter->file_size,
                // Páginas de cómic del capítulo (vacío en capítulos de texto/PDF).
                // Si trae páginas, el lector las muestra como webtoon continuo.
                'pages' => $chapter->comicPages()->get()->map(fn ($p) => [
                    'id' => $p->id,
                    'image_url' => $p->image_link,
                    'position' => (int) $p->position,
                    'width' => $p->width,
                    'height' => $p->height,
                ])->values(),
                'published_at' => $chapter->created_at?->toIso8601String(),
            ],
            'book' => [
                'id' => $book->id,
                'title' => $book->title,
                'slug' => $book->slug,
                'cover' => $book->cover,
                'work_format' => $book->work_format,
                'author_note_start' => $book->author_note_start,
                'author_note_end' => $book->author_note_end,
            ],
            'prev' => $prev ? ['number' => $prev->number, 'title' => $prev->title] : null,
            'next' => $next ? ['number' => $next->number, 'title' => $next->title] : null,
        ]);
    }

    /**
     * Devuelve el texto del capítulo para leer inline. Si no tiene `body` pero
     * trae un TXT/DOCX/EPUB adjunto, extrae el texto con los parsers del
     * importador y lo cachea en `body` (conversión perezosa de archivos
     * heredados → lectura en plataforma, sin descargas). El PDF/imagen NO se
     * convierte: lo muestra el lector con pdf.js.
     */
    private function readableBody(Chapter $chapter): ?string
    {
        if ($chapter->body !== null && trim($chapter->body) !== '') {
            return $chapter->body;
        }

        $ext = strtolower((string) $chapter->file_type);
        if (! in_array($ext, ['txt', 'docx', 'epub'], true)) {
            return $chapter->body;
        }

        // Localiza el archivo: disco público (file_path) o descarga la URL.
        $absPath = null;
        $tmp = null;
        if ($chapter->file_path && Storage::disk('public')->exists($chapter->file_path)) {
            $absPath = Storage::disk('public')->path($chapter->file_path);
        } elseif ($chapter->file_url) {
            try {
                $bytes = Http::timeout(20)->get($chapter->file_url)->body();
                if ($bytes !== '') {
                    $tmp = tempnam(sys_get_temp_dir(), 'read').'.'.$ext;
                    file_put_contents($tmp, $bytes);
                    $absPath = $tmp;
                }
            } catch (\Throwable $e) {
                return $chapter->body;
            }
        }
        if (! $absPath) {
            return $chapter->body;
        }

        try {
            $parsed = app(ImportManager::class)->parse($absPath, $ext);
        } catch (\Throwable $e) {
            report($e);
            $parsed = [];
        } finally {
            if ($tmp && is_file($tmp)) {
                @unlink($tmp);
            }
        }

        if (empty($parsed)) {
            return $chapter->body;
        }

        $parts = [];
        foreach ($parsed as $pc) {
            if (trim($pc->title) !== '') {
                $parts[] = trim($pc->title);
            }
            if (trim($pc->body) !== '') {
                $parts[] = trim($pc->body);
            }
        }
        $body = trim(implode("\n\n", $parts));
        if ($body === '') {
            return $chapter->body;
        }

        // Cachea para no re-parsear en cada lectura.
        $chapter->update(['body' => $body]);

        return $body;
    }

    /**
     * GET /api/books/{book:slug}/pages
     * Páginas de cómic en orden (para el viewer).
     */
    public function pages(Book $book): JsonResponse
    {
        $this->assertPubliclyReadable($book);

        $pages = $book->comicPages()
            ->whereNull('chapter_id')
            ->orderBy('position')
            ->get(['id', 'image_url', 'image_path', 'position', 'width', 'height']);

        // Un cómic puede ser un PDF (se lee como webtoon con pdf.js en vez de
        // partirlo en imágenes, que el servidor no puede rasterizar). Se sirve a
        // través del file-proxy del API (streaming server-side desde R2/public con
        // CORS *), no la URL cruda de R2 — así el lector siempre puede cargarlo.
        $isPdf = strtolower((string) $book->file_type) === 'pdf'
            && ($book->file_path || $book->file_url);

        return response()->json([
            'data' => $pages->map(fn ($p) => [
                'id' => $p->id,
                'image_url' => $p->image_link,
                'position' => (int) $p->position,
                'width' => $p->width,
                'height' => $p->height,
            ]),
            'book' => [
                'id' => $book->id,
                'title' => $book->title,
                'slug' => $book->slug,
                'work_format' => $book->work_format,
                'file_type' => $book->file_type,
                'pdf_url' => $isPdf ? url("/api/books/{$book->slug}/file-proxy") : null,
            ],
        ]);
    }

    /**
     * GET /api/chapters/{chapter}/file-proxy
     * Sirve el archivo (PDF) de un capítulo con streaming server-side desde
     * public/R2 y CORS abierto, para que el lector lo muestre sin descargar.
     */
    public function chapterFileProxy(Request $request, Chapter $chapter)
    {
        $chapter->loadMissing('book');
        $book = $chapter->book;
        abort_unless((bool) $book, 404, 'Capítulo no disponible.');
        $this->assertPubliclyReadable($book);

        // Capítulo de pago → solo con URL firmada (ruta segura del lector).
        if ((int) $chapter->coin_price > 0 && ! $request->hasValidSignature()) {
            abort(403, 'Usa la ruta segura para este capítulo.');
        }

        $contentType = match (strtolower((string) $chapter->file_type)) {
            'pdf' => 'application/pdf',
            'epub' => 'application/epub+zip',
            default => 'application/octet-stream',
        };
        $filename = "cap-{$chapter->number}." . ($chapter->file_type ?: 'bin');
        $headers = [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=86400',
        ];

        if ($chapter->file_path) {
            $public = Storage::disk('public');
            if ($public->exists($chapter->file_path)) {
                return $public->download($chapter->file_path, $filename, $headers);
            }
            try {
                if (Storage::disk('r2_books')->exists($chapter->file_path)) {
                    return Storage::disk('r2_books')->download($chapter->file_path, $filename, $headers);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('chapterFileProxy R2: ' . $e->getMessage());
            }
        }
        if ($chapter->file_url) {
            return redirect($chapter->file_url);
        }
        abort(404, 'Archivo no disponible.');
    }

    /**
     * GET /api/books/{book:slug}/download
     * Descarga la obra en UN solo archivo, solo si el creador lo permite.
     * - Serie / capítulos de texto → EPUB con los capítulos a los que el lector
     *   tiene acceso (respeta el paywall: no descarga lo que no ha desbloqueado).
     * - Libro completo con archivo subido (gratis) → el archivo original.
     */
    public function download(Request $request, Book $book): Response
    {
        $this->assertPubliclyReadable($book);

        if (! $book->allow_download) {
            abort(403, 'El autor no permite descargar esta obra.');
        }

        $viewer = $request->user('sanctum');

        // 1) Capítulos de texto accesibles → EPUB.
        $accessibleText = $book->chapters()
            ->where('is_published', true)
            ->orderBy('position')
            ->get()
            ->filter(fn (Chapter $c) => $this->unlocks->chapterAccess($viewer, $book, $c)['access'])
            ->map(function (Chapter $c) {
                $c->setAttribute('body', $this->readableBody($c));
                return $c;
            })
            ->filter(fn (Chapter $c) => trim((string) $c->body) !== '')
            ->values();

        if ($accessibleText->isNotEmpty()) {
            $epub = app(BookEpubExporter::class)->build($book, $accessibleText);
            $book->increment('downloads_count');
            $filename = (Str::slug($book->title) ?: 'obra').'.epub';

            return response($epub, 200, [
                'Content-Type' => 'application/epub+zip',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Content-Length' => (string) strlen($epub),
                'Cache-Control' => 'no-store',
            ]);
        }

        // 2) Libro completo con archivo subido (solo obras gratuitas).
        if ($book->file_path && $book->access_type === 'free' && Storage::disk('public')->exists($book->file_path)) {
            $book->increment('downloads_count');
            $ext = $book->file_type === 'pdf' ? 'pdf' : ($book->file_type === 'epub' ? 'epub' : 'epub');

            return Storage::disk('public')->download($book->file_path, (Str::slug($book->title) ?: 'obra').'.'.$ext);
        }

        abort(404, 'No hay contenido descargable disponible para esta obra.');
    }

    private function assertPubliclyReadable(Book $book): void
    {
        abort_if(! $book->is_published || $book->is_hidden, 404);
    }
}
