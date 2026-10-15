<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Notification;
use App\Models\User;
use App\Services\FileGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ChaptersController extends Controller
{
    /** GET /api/me/works/{work}/chapters */
    public function index(Request $request, Book $work): JsonResponse
    {
        $this->assertOwnership($work, $request->user());

        $chapters = $work->chapters()->get();

        return response()->json([
            'data' => $chapters->map(fn ($c) => $this->serialize($c)),
        ]);
    }

    /** POST /api/me/works/{work}/chapters */
    public function store(Request $request, Book $work): JsonResponse
    {
        $this->assertOwnership($work, $request->user());

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:500000'],
            'author_note' => ['nullable', 'string', 'max:5000'],
            'schedule_at' => ['nullable', 'date', 'after:now'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $position = (int) ($work->chapters()->max('position') ?? 0) + 1;
        $number = (int) ($work->chapters()->max('number') ?? 0) + 1;

        $chapter = $work->chapters()->create([
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'author_note' => $data['author_note'] ?? null,
            'schedule_at' => $data['schedule_at'] ?? null,
            'is_published' => $data['is_published'] ?? false,
            'position' => $position,
            'number' => $number,
        ]);

        return response()->json(['data' => $this->serialize($chapter)], 201);
    }

    /** PATCH /api/me/chapters/{chapter} */
    public function update(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertChapterOwnership($chapter, $request->user());

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string', 'max:500000'],
            'author_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'number' => ['sometimes', 'integer', 'min:1'],
            'schedule_at' => ['sometimes', 'nullable', 'date'],
            'is_published' => ['sometimes', 'boolean'],
        ]);

        $wasPublished = (bool) $chapter->is_published;
        $chapter->update($data);

        // Trigger: capítulo recién publicado → notificar a favoritos + seguidores del creador
        if (! $wasPublished && (bool) $chapter->is_published) {
            $this->notifyChapterPublished($chapter);
        }

        return response()->json(['data' => $this->serialize($chapter->fresh())]);
    }

    /**
     * Notifica a (a) usuarios que tienen el libro en favoritos
     *           (b) seguidores del creador del libro
     * Una notificación por usuario (sin duplicar).
     */
    private function notifyChapterPublished(Chapter $chapter): void
    {
        $book = $chapter->book;
        if (! $book) return;

        // Usuarios a notificar — favoritos del libro
        $favoritedBy = $book->favoritedBy()->pluck('users.id');

        // + seguidores del creador
        $followers = $book->creator
            ? $book->creator->followers()->pluck('users.id')
            : collect();

        $userIds = $favoritedBy->merge($followers)
            ->reject(fn ($id) => $id === $book->creator_id)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) return;

        $title = "Nuevo capítulo: " . $chapter->title;
        $body = "El capítulo {$chapter->number} de \"{$book->title}\" ya está disponible.";
        $link = "/libro/{$book->slug}/capitulo/{$chapter->number}";
        $now = now();

        $rows = $userIds->map(fn ($uid) => [
            'user_id' => $uid,
            'category' => 'reading',
            'type' => 'chapter_new',
            'emoji' => '📖',
            'title' => $title,
            'body' => $body,
            'link' => $link,
            'data' => json_encode([
                'book_id' => $book->id,
                'chapter_id' => $chapter->id,
                'chapter_number' => $chapter->number,
            ]),
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            Notification::insert($chunk);
        }
    }

    /** DELETE /api/me/chapters/{chapter} */
    public function destroy(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertChapterOwnership($chapter, $request->user());

        // Borrar archivo asociado si está en storage local
        if ($chapter->file_path) {
            Storage::disk('public')->delete($chapter->file_path);
        }

        $chapter->delete();

        return response()->json(['message' => 'Capítulo eliminado.']);
    }

    /** POST /api/me/chapters/{chapter}/duplicate */
    public function duplicate(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertChapterOwnership($chapter, $request->user());

        $work = $chapter->book;
        $position = (int) ($work->chapters()->max('position') ?? 0) + 1;
        $number = (int) ($work->chapters()->max('number') ?? 0) + 1;

        $copy = $work->chapters()->create([
            'title' => $chapter->title . ' (copia)',
            'body' => $chapter->body,
            'author_note' => $chapter->author_note,
            'position' => $position,
            'number' => $number,
            'is_published' => false,
        ]);

        return response()->json(['data' => $this->serialize($copy)], 201);
    }

    /**
     * POST /api/me/works/{work}/chapters/reorder
     * Body: { order: [chapterId1, chapterId2, ...] }
     */
    public function reorder(Request $request, Book $work): JsonResponse
    {
        $this->assertOwnership($work, $request->user());

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $ownChapterIds = $work->chapters()->pluck('id')->all();
        $valid = array_values(array_intersect($data['order'], $ownChapterIds));

        DB::transaction(function () use ($valid) {
            foreach ($valid as $i => $id) {
                Chapter::where('id', $id)->update([
                    'position' => $i + 1,
                    'number' => $i + 1,
                ]);
            }
        });

        return response()->json(['message' => 'Orden actualizado.']);
    }

    /** POST /api/me/chapters/{chapter}/file (upload archivo del capítulo). */
    public function uploadFile(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertChapterOwnership($chapter, $request->user());

        // Tope general 1 GB; FileGuard aplica el límite fino por tipo y por PLAN
        // del creador (EPUB/PDF suben a 500 MB / 1 GB para verificado/destacado/Plus).
        $request->validate([
            'file' => ['required', 'file', 'max:1048576'],
        ]);

        $file = $request->file('file');

        // Validación de seguridad real (MIME + magic bytes + blocklist + tamaño por plan).
        // Lanza 422 si el archivo es peligroso o renombrado.
        $ext = app(FileGuard::class)->validateWork($file, 'file', $request->user());

        // Borrar archivo previo
        if ($chapter->file_path) {
            Storage::disk('public')->delete($chapter->file_path);
        }

        // Nombre SIEMPRE aleatorio + extensión canónica (nunca el nombre del cliente).
        $filename = 'chapters/' . Str::random(40) . '.' . $ext;
        $file->storeAs('', $filename, 'public');

        $chapter->update([
            'file_path' => $filename,
            'file_url' => Storage::disk('public')->url($filename),
            'file_type' => $ext,
            'file_size' => $file->getSize(),
        ]);

        // Si la obra YA está publicada, publicar también este capítulo: subir un
        // archivo a una serie publicada es señal de que el capítulo está listo
        // para leerse (antes quedaba en borrador y la ficha mostraba "0 cap.").
        if (! $chapter->is_published && $chapter->book?->is_published && ! $chapter->schedule_at) {
            $chapter->update(['is_published' => true]);
            $this->notifyChapterPublished($chapter->fresh());
        }

        return response()->json(['data' => $this->serialize($chapter->fresh())]);
    }

    /** POST /api/me/chapters/{chapter}/cover (portada/miniatura del episodio). */
    public function uploadCover(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertChapterOwnership($chapter, $request->user());

        $request->validate([
            'cover' => ['required', 'file', 'max:10240'], // 10 MB; FileGuard valida de verdad
        ]);

        // Imagen real (MIME + magic bytes + dimensiones). Lanza 422 si no es válida.
        $ext = app(FileGuard::class)->validateImage(
            $request->file('cover'),
            'cover',
            FileGuard::IMAGE_MAX_BYTES,
            FileGuard::COVER_MAX_DIM,
        );

        if ($chapter->cover_path) {
            Storage::disk('public')->delete($chapter->cover_path);
        }

        $filename = 'chapter-covers/' . Str::random(40) . '.' . $ext;
        $request->file('cover')->storeAs('', $filename, 'public');

        $chapter->update([
            'cover_path' => $filename,
            'cover_url' => Storage::disk('public')->url($filename),
        ]);

        return response()->json(['data' => $this->serialize($chapter->fresh())]);
    }

    private function assertOwnership(Book $work, $user): void
    {
        if ($work->creator_id !== $user->id) {
            abort(403, 'Esta obra no es tuya.');
        }
    }

    private function assertChapterOwnership(Chapter $chapter, $user): void
    {
        $chapter->loadMissing('book');
        // book puede ser null si la obra fue eliminada (capítulo huérfano):
        // antes esto explotaba con 500 "creator_id on null".
        if (! $chapter->book) {
            abort(404, 'La obra de este capítulo ya no existe.');
        }
        if ($chapter->book->creator_id !== $user->id) {
            abort(403, 'Este capítulo no es tuyo.');
        }
    }

    private function serialize(Chapter $c): array
    {
        return [
            'id' => $c->id,
            'book_id' => $c->book_id,
            'number' => (int) $c->number,
            'title' => $c->title,
            'body' => $c->body,
            'author_note' => $c->author_note,
            'position' => (int) $c->position,
            'schedule_at' => $c->schedule_at?->toIso8601String(),
            'is_published' => (bool) $c->is_published,
            'coin_price' => (int) $c->coin_price,
            'early_access_until' => $c->early_access_until?->toIso8601String(),
            'file_url' => $c->file_link,
            'file_type' => $c->file_type,
            'file_size' => $c->file_size ? (int) $c->file_size : null,
            'cover_url' => $c->cover_link,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }
}
