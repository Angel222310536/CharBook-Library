<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Notification;
use App\Services\FileGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Endpoints "self-service" para que un creador gestione SUS obras.
 * Modelo abierto: cualquier user autenticado puede crear obras como creador.
 */
class MyWorksController extends Controller
{
    public const CONTENT_TYPES = [
        'novela', 'cuento', 'ensayo', 'poesia', 'no_ficcion', 'experimental',
    ];

    public const AGE_RATINGS = ['all', 'teen', 'mature', 'explicit'];

    public const WORK_FORMATS = ['serie', 'libro_completo', 'comic'];

    public const COMMENTS_MODES = ['on', 'followers_only', 'off'];

    /**
     * GET /api/me/works/{work}
     * Detalle completo de una obra mía (para editor).
     */
    public function show(Request $request, Book $work): JsonResponse
    {
        $user = $request->user();
        if ($work->creator_id !== $user->id) {
            abort(403, 'Esta obra no es tuya.');
        }

        $work->load(['categories:id,name,slug']);

        return response()->json([
            'data' => array_merge(
                $this->serializeWork($work),
                [
                    'parent_category_id' => $work->parent_category_id,
                    'subcategory_id' => $work->subcategory_id,
                    'work_format' => $work->work_format,
                    'comments_mode' => $work->comments_mode,
                    'ratings_enabled' => (bool) $work->ratings_enabled,
                    'seo_title' => $work->seo_title,
                    'seo_description' => $work->seo_description,
                    'seo_keywords' => $work->seo_keywords ?? [],
                    'author_note_start' => $work->author_note_start,
                    'author_note_end' => $work->author_note_end,
                    'schedule_at' => $work->schedule_at?->toIso8601String(),
                ]
            ),
        ]);
    }

    /**
     * GET /api/me/works
     * Lista paginada de las obras que YO publiqué.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->query('status'); // draft | review | published | all

        $query = Book::query()
            ->where('creator_id', $user->id)
            ->with('author:id,name,slug')
            ->orderByDesc('updated_at');

        if ($status && $status !== 'all') {
            $query->where('submission_status', $status);
        }

        $works = $query->paginate(20)->withQueryString();

        return response()->json([
            'data' => $works->getCollection()->map(fn (Book $b) => $this->serializeWork($b)),
            'meta' => [
                'current_page' => $works->currentPage(),
                'last_page' => $works->lastPage(),
                'per_page' => $works->perPage(),
                'total' => $works->total(),
            ],
        ]);
    }

    /**
     * POST /api/me/works
     * Crear una obra nueva en estado borrador.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:5000'],
            'synopsis' => ['nullable', 'string', 'max:1000'],
            // content_type sigue siendo aceptado por compatibilidad pero ya no se requiere
            'content_type' => ['sometimes', 'nullable', Rule::in(self::CONTENT_TYPES)],
            // Formato de obra (wizard): serie | libro_completo | comic
            'work_format' => ['required', Rule::in(self::WORK_FORMATS)],
            'language' => ['required', 'string', 'size:2'],
            'age_rating' => ['required', Rule::in(self::AGE_RATINGS)],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            // Categorías jerárquicas (wizard)
            'parent_category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:categories,id'],
            'category_ids' => ['nullable', 'array', 'max:3'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'copyright_holder' => ['required', 'string', 'max:200'],
            'is_original' => ['required', 'accepted'],
            // El creador DEBE decidir si su obra es descargable (sí/no).
            'allow_download' => ['required', 'boolean'],
            // cover_url puede ser http(s), data:image/...;base64,... o null
            'cover_url' => ['nullable', 'string', 'max:10000000', 'regex:/^(https?:\/\/|data:image\/)/'],
            'cover_path' => ['nullable', 'string', 'max:500'],
            // Configuración de lectores
            'comments_mode' => ['sometimes', Rule::in(self::COMMENTS_MODES)],
            'ratings_enabled' => ['sometimes', 'boolean'],
            // SEO
            'seo_title' => ['nullable', 'string', 'max:200'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'seo_keywords' => ['nullable', 'array', 'max:20'],
            'seo_keywords.*' => ['string', 'max:50'],
            // Notas del autor
            'author_note_start' => ['nullable', 'string', 'max:5000'],
            'author_note_end' => ['nullable', 'string', 'max:5000'],
            // Programación
            'schedule_at' => ['nullable', 'date', 'after:now'],
            'publish_now' => ['sometimes', 'boolean'],
        ], [
            'is_original.accepted' => 'Debes confirmar que la obra es original.',
            'cover_url.regex' => 'La portada debe ser una URL válida o una imagen subida.',
            'allow_download.required' => 'Indica si tu obra se puede descargar o no.',
        ]);

        // Si la portada llega como data: URL (drag & drop del dropzone),
        // la decodificamos y guardamos como archivo en storage local público.
        [$coverUrl, $coverPath] = $this->processCover($data['cover_url'] ?? null);

        // Determinar estado inicial según schedule / publish_now
        $isScheduled = ! empty($data['schedule_at']);
        $publishNow = $data['publish_now'] ?? false;

        $isPublished = $publishNow && ! $isScheduled;
        $submissionStatus = $isScheduled
            ? 'scheduled'
            : ($publishNow ? 'published' : 'draft');

        $work = Book::create([
            'creator_id' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'synopsis' => $data['synopsis'] ?? null,
            'content_type' => $data['content_type'] ?? null,
            'work_format' => $data['work_format'],
            'parent_category_id' => $data['parent_category_id'] ?? null,
            'subcategory_id' => $data['subcategory_id'] ?? null,
            'language' => $data['language'],
            'age_rating' => $data['age_rating'],
            'tags' => $data['tags'] ?? [],
            'copyright_holder' => $data['copyright_holder'],
            'is_original' => true,
            'cover_url' => $coverUrl,
            'cover_path' => $coverPath ?? ($data['cover_path'] ?? null),
            'access_type' => 'free',
            'allow_download' => $data['allow_download'],
            'file_type' => 'pdf',
            'comments_mode' => $data['comments_mode'] ?? 'on',
            'ratings_enabled' => $data['ratings_enabled'] ?? true,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'seo_keywords' => $data['seo_keywords'] ?? null,
            'author_note_start' => $data['author_note_start'] ?? null,
            'author_note_end' => $data['author_note_end'] ?? null,
            'schedule_at' => $data['schedule_at'] ?? null,
            'is_published' => $isPublished,
            'submission_status' => $submissionStatus,
        ]);

        // Vincular categorías si vinieron
        if (! empty($data['category_ids'])) {
            $work->categories()->sync($data['category_ids']);
        }

        // Trigger: si la obra se publica directamente, notificar a seguidores del creador
        if ($isPublished) {
            $this->notifyNewWork($work, $user);
        }

        return response()->json(['data' => $this->serializeWork($work)], 201);
    }

    private function notifyNewWork(Book $work, $creator): void
    {
        $followerIds = $creator->followers()->pluck('users.id');
        if ($followerIds->isEmpty()) return;

        $now = now();
        $rows = $followerIds->map(fn ($uid) => [
            'user_id' => $uid,
            'category' => 'reading',
            'type' => 'work_new',
            'emoji' => '✨',
            'title' => "Nueva obra de {$creator->name}",
            'body' => "\"{$work->title}\" ya está disponible.",
            'link' => "/libro/{$work->slug}",
            'data' => json_encode(['book_id' => $work->id, 'creator_id' => $creator->id]),
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            Notification::insert($chunk);
        }
    }

    /**
     * PATCH /api/me/works/{work}
     */
    public function update(Request $request, Book $work): JsonResponse
    {
        $user = $request->user();
        if ($work->creator_id !== $user->id) {
            abort(403, 'Esta obra no es tuya.');
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'synopsis' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'content_type' => ['sometimes', Rule::in(self::CONTENT_TYPES)],
            'language' => ['sometimes', 'string', 'size:2'],
            'age_rating' => ['sometimes', Rule::in(self::AGE_RATINGS)],
            'tags' => ['sometimes', 'nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'copyright_holder' => ['sometimes', 'string', 'max:200'],
            'allow_download' => ['sometimes', 'boolean'],
            'cover_url' => ['sometimes', 'nullable', 'string', 'max:10000000'],
            'submission_status' => ['sometimes', Rule::in(['draft', 'review', 'published'])],
        ]);

        // Sincronizar is_published con submission_status si vino
        if (isset($data['submission_status'])) {
            $data['is_published'] = $data['submission_status'] === 'published';
        }

        $work->update($data);

        // Al PUBLICAR la obra, publicar también sus capítulos con contenido que
        // sigan en borrador (sin programación futura). Sin esto, una serie
        // publicada quedaba con "0 cap." y no se podía leer — el creador
        // espera que publicar la obra publique lo que escribió.
        if (($data['submission_status'] ?? null) === 'published') {
            $work->chapters()
                ->where('is_published', false)
                ->where(fn ($q) => $q->whereNull('schedule_at')->orWhere('schedule_at', '<=', now()))
                ->where(fn ($q) => $q
                    ->whereNotNull('file_url')
                    ->orWhereNotNull('file_path')
                    ->orWhere(fn ($qq) => $qq->whereNotNull('body')->where('body', '<>', '')))
                ->update(['is_published' => true]);
        }

        return response()->json(['data' => $this->serializeWork($work->fresh())]);
    }

    /**
     * DELETE /api/me/works/{work}
     */
    public function destroy(Request $request, Book $work): JsonResponse
    {
        $user = $request->user();
        if ($work->creator_id !== $user->id) {
            abort(403, 'Esta obra no es tuya.');
        }

        $work->delete();

        return response()->json(['message' => 'Obra eliminada.']);
    }

    /**
     * Procesa la portada recibida:
     *  - http(s)://... → se queda como cover_url
     *  - data:image/...;base64,... → se decodifica y guarda como archivo,
     *    devuelve la URL pública y el path relativo
     *  - null → null, null
     *
     * @return array{0: string|null, 1: string|null}  [cover_url, cover_path]
     */
    private function processCover(?string $raw): array
    {
        if (! $raw) return [null, null];

        // URL http(s) ordinaria
        if (preg_match('/^https?:\/\//i', $raw)) {
            return [$raw, null];
        }

        // data: URL base64 — validada por contenido real (no solo el prefijo).
        if (str_starts_with($raw, 'data:image/')) {
            // Lanza 422 si el contenido decodificado no es una imagen jpg/png/webp.
            [$binary, $ext] = app(FileGuard::class)->validateImageDataUrl($raw, 'cover_url');

            $filename = 'covers/' . Str::random(40) . '.' . $ext;
            Storage::disk('public')->put($filename, $binary);

            // Devolvemos la URL pública (asset path) y el cover_path
            return [Storage::disk('public')->url($filename), $filename];
        }

        return [null, null];
    }

    /**
     * POST /api/me/works/{work}/file
     * Sube el archivo principal de un libro_completo (EPUB / PDF / DOCX).
     */
    public function uploadFile(Request $request, Book $work): JsonResponse
    {
        $user = $request->user();
        if ($work->creator_id !== $user->id) {
            abort(403, 'Esta obra no es tuya.');
        }

        $request->validate([
            // Tope general 1 GB; FileGuard aplica el límite fino por tipo y por PLAN
            // (TXT 5 · DOCX 25 · EPUB 50 · PDF 100 MB; premium sube EPUB/PDF a 500 MB / 1 GB).
            'file' => ['required', 'file', 'max:1048576'],
        ]);

        $file = $request->file('file');

        // Validación de seguridad real (MIME + magic bytes + blocklist + tamaño por plan).
        $ext = app(FileGuard::class)->validateWork($file, 'file', $user);

        // Borrar archivo previo
        if ($work->file_path) {
            Storage::disk('public')->delete($work->file_path);
        }

        // Nombre aleatorio + extensión canónica derivada del contenido validado.
        $filename = 'books/' . Str::random(40) . '.' . $ext;
        $file->storeAs('', $filename, 'public');

        $work->update([
            'file_path' => $filename,
            'file_url' => Storage::disk('public')->url($filename),
            'file_type' => $ext === 'epub' ? 'epub' : ($ext === 'pdf' ? 'pdf' : 'both'),
            'file_size' => $file->getSize(),
        ]);

        return response()->json([
            'data' => $this->serializeWork($work->fresh()),
            'message' => 'Archivo subido.',
        ]);
    }

    /** DELETE /api/me/works/{work}/file */
    public function removeFile(Request $request, Book $work): JsonResponse
    {
        $user = $request->user();
        if ($work->creator_id !== $user->id) {
            abort(403, 'Esta obra no es tuya.');
        }

        if ($work->file_path) {
            Storage::disk('public')->delete($work->file_path);
        }

        $work->update([
            'file_path' => null,
            'file_url' => null,
            'file_size' => null,
        ]);

        return response()->json(['message' => 'Archivo eliminado.']);
    }

    private function serializeWork(Book $b): array
    {
        return [
            'id' => $b->id,
            'title' => $b->title,
            'slug' => $b->slug,
            'cover' => $b->cover,
            'description' => $b->description,
            'content_type' => $b->content_type,
            'language' => $b->language,
            'age_rating' => $b->age_rating,
            'tags' => $b->tags ?? [],
            'copyright_holder' => $b->copyright_holder,
            'submission_status' => $b->submission_status,
            'is_published' => (bool) $b->is_published,
            'work_format' => $b->work_format,
            'allow_download' => (bool) $b->allow_download,
            'file_url' => $b->file_url,
            'file_type' => $b->file_type,
            'file_size' => $b->file_size ? (int) $b->file_size : null,
            'monetization' => $b->monetization ?? [],
            'in_unlimited_catalog' => (bool) $b->in_unlimited_catalog,
            'views_count' => (int) $b->views_count,
            'unique_readers_count' => (int) $b->views_count,
            'total_readings_count' => (int) $b->total_readings_count,
            'favorites_count' => (int) $b->favorites_count,
            'rating_average' => (float) $b->rating_average,
            'rating_count' => (int) $b->rating_count,
            'created_at' => $b->created_at?->toIso8601String(),
            'updated_at' => $b->updated_at?->toIso8601String(),
        ];
    }
}
