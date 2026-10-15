<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ComicPage;
use App\Models\FileUploadAudit;
use App\Services\FileGuard;
use App\Services\Import\EpubChapterParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ComicPagesController extends Controller
{
    /** GET /api/me/works/{work}/pages */
    public function index(Request $request, Book $work): JsonResponse
    {
        $this->assertOwnership($work, $request->user());

        return response()->json([
            'data' => $work->comicPages()->get()->map(fn ($p) => $this->serialize($p)),
        ]);
    }

    /**
     * POST /api/me/works/{work}/pages
     * Acepta múltiples archivos en files[]
     */
    public function store(Request $request, Book $work): JsonResponse
    {
        $this->assertOwnership($work, $request->user());

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:50'],
            'files.*' => ['file'],
        ]);

        // Tope anti-DoS de páginas por obra.
        $existing = (int) $work->comicPages()->count();
        if ($existing + count($request->file('files')) > FileGuard::MAX_COMIC_PAGES) {
            throw ValidationException::withMessages([
                'files' => ['El cómic supera el máximo de '.FileGuard::MAX_COMIC_PAGES.' páginas.'],
            ]);
        }

        $position = (int) ($work->comicPages()->max('position') ?? 0);
        $created = [];
        $guard = app(FileGuard::class);

        foreach ($request->file('files') as $i => $file) {
            // Imagen de cómic: hasta 20 MB y 8000×8000; MIME real + magic bytes +
            // dimensiones. Rechaza (422) imágenes falsas/renombradas y audita.
            try {
                $ext = $guard->validateImage($file, "files.{$i}", FileGuard::COMIC_IMAGE_MAX_BYTES, FileGuard::MAX_IMAGE_DIM);
            } catch (ValidationException $e) {
                FileUploadAudit::record($request, 'comic_page', $file, 'rejected', collect($e->errors())->flatten()->first());
                throw $e;
            }

            $filename = 'pages/'.Str::random(40).'.'.$ext;
            $file->storeAs('', $filename, 'public');

            $position++;
            $page = $work->comicPages()->create([
                'image_path' => $filename,
                'image_url' => null,
                'position' => $position,
                'size' => $file->getSize(),
            ]);

            try {
                $info = @getimagesize(Storage::disk('public')->path($filename));
                if ($info) {
                    $page->update(['width' => $info[0], 'height' => $info[1]]);
                }
            } catch (\Throwable) {
                // Ignorar — las dimensiones son opcionales
            }

            FileUploadAudit::record($request, 'comic_page', $file, 'accepted');
            $created[] = $this->serialize($page->fresh());
        }

        return response()->json([
            'data' => $created,
            'message' => count($created).' página(s) subida(s).',
        ], 201);
    }

    /**
     * POST /api/me/works/{work}/pages/import
     * Importa páginas de cómic desde un EPUB de imágenes (extrae las imágenes en
     * orden de lectura). PDF requiere Imagick/Ghostscript (no disponible aquí).
     */
    public function import(Request $request, Book $work): JsonResponse
    {
        $this->assertOwnership($work, $request->user());

        // Tope general 1 GB; FileGuard aplica el límite fino por tipo y por PLAN.
        $request->validate([
            'file' => ['required', 'file', 'max:1048576'],
        ]);

        $guard = app(FileGuard::class);
        $file = $request->file('file');
        $declaredExt = strtolower($file->getClientOriginalExtension());
        $maxBytes = $guard->premiumWorkMaxBytes($request->user());

        try {
            // 1) CBZ / ZIP de imágenes → páginas (escaneo profundo + extracción segura).
            if (in_array($declaredExt, FileGuard::COMIC_ARCHIVE_EXTS, true)) {
                $images = $guard->validateComicArchive($file, $maxBytes, 'file');
                $existing = (int) $work->comicPages()->count();
                if ($existing + count($images) > FileGuard::MAX_COMIC_PAGES) {
                    throw ValidationException::withMessages(['file' => ['El cómic supera el máximo de '.FileGuard::MAX_COMIC_PAGES.' páginas.']]);
                }
                $created = $this->storeArchiveImages($work, null, $file->getRealPath(), $images);
                FileUploadAudit::record($request, 'comic_archive', $file, 'accepted', count($created).' páginas (cbz/zip)');

                return response()->json(['data' => $created, 'message' => count($created).' página(s) importada(s).'], 201);
            }

            // 2) EPUB de imágenes. PDF NO: el servidor no rasteriza (sin Imagick/Ghostscript).
            $ext = $guard->validateWork($file, 'file', $request->user());

            if ($ext === 'pdf') {
                FileUploadAudit::record($request, 'comic_archive', $file, 'rejected', 'PDF: rasterización no disponible');

                return response()->json([
                    'message' => 'Este servidor no puede convertir un PDF en páginas de imagen (requiere Imagick/Ghostscript). Usa un CBZ/ZIP o EPUB de imágenes, o sube las imágenes directamente.',
                    'errors' => ['file' => ['PDF no soportado para extraer páginas en este servidor.']],
                ], 422);
            }
            if ($ext !== 'epub') {
                FileUploadAudit::record($request, 'comic_archive', $file, 'rejected', 'formato no soportado: '.$ext);

                return response()->json([
                    'message' => 'Solo se puede importar un cómic desde CBZ, ZIP o EPUB de imágenes.',
                    'errors' => ['file' => ['Formato no soportado para importar cómic.']],
                ], 422);
            }

            $guard->assertSafeArchive($file->getRealPath(), 'file');

            try {
                $images = (new EpubChapterParser)->extractComicImages($file->getRealPath());
            } catch (\Throwable $e) {
                report($e);
                FileUploadAudit::record($request, 'comic_archive', $file, 'rejected', 'EPUB ilegible');

                return response()->json(['message' => 'No se pudo leer el EPUB. Revisa que sea un archivo válido.'], 422);
            }

            if (empty($images)) {
                FileUploadAudit::record($request, 'comic_archive', $file, 'rejected', 'EPUB sin imágenes');

                return response()->json([
                    'message' => 'No se encontraron imágenes en el EPUB.',
                    'errors' => ['file' => ['El EPUB no contiene páginas de imagen.']],
                ], 422);
            }

            $images = array_slice($images, 0, FileGuard::MAX_COMIC_PAGES);
            $position = (int) ($work->comicPages()->max('position') ?? 0);
            $created = [];
            foreach ($images as $img) {
                $filename = 'pages/'.Str::random(40).'.'.$img['ext'];
                Storage::disk('public')->put($filename, $img['bytes']);
                $position++;
                $page = $work->comicPages()->create([
                    'image_path' => $filename,
                    'image_url' => null,
                    'position' => $position,
                    'size' => strlen($img['bytes']),
                ]);
                $info = @getimagesizefromstring($img['bytes']);
                if ($info) {
                    $page->update(['width' => $info[0], 'height' => $info[1]]);
                }
                $created[] = $this->serialize($page->fresh());
            }
            FileUploadAudit::record($request, 'comic_archive', $file, 'accepted', count($created).' páginas (epub)');

            return response()->json(['data' => $created, 'message' => count($created).' página(s) importada(s) del EPUB.'], 201);
        } catch (ValidationException $e) {
            FileUploadAudit::record($request, 'comic_archive', $file, 'rejected', collect($e->errors())->flatten()->first());
            throw $e;
        }
    }

    /**
     * Re-abre el ZIP/CBZ ya validado y extrae cada imagen por nombre, una a una
     * (memoria acotada), guardándola como página. `$images` viene de
     * FileGuard::validateComicArchive (ya en orden de lectura).
     *
     * @param  array<int, array{name:string, ext:string, width:?int, height:?int}>  $images
     */
    private function storeArchiveImages(Book $work, ?Chapter $chapter, string $zipPath, array $images): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($zipPath) !== true) {
            abort(422, 'No se pudo abrir el archivo del cómic.');
        }

        $scope = $chapter
            ? $chapter->comicPages()
            : $work->comicPages()->whereNull('chapter_id');
        $position = (int) ($scope->max('position') ?? 0);

        $created = [];
        foreach ($images as $img) {
            $bytes = $zip->getFromName($img['name']);
            if ($bytes === false) {
                continue;
            }
            $filename = 'pages/'.Str::random(40).'.'.$img['ext'];
            $size = strlen($bytes);
            Storage::disk('public')->put($filename, $bytes);
            unset($bytes);

            $position++;
            $page = ComicPage::create([
                'book_id' => $work->id,
                'chapter_id' => $chapter?->id,
                'image_path' => $filename,
                'image_url' => null,
                'position' => $position,
                'size' => $size,
                'width' => $img['width'],
                'height' => $img['height'],
            ]);
            $created[] = $this->serialize($page->fresh());
        }
        $zip->close();

        return $created;
    }

    /** DELETE /api/me/pages/{page} */
    public function destroy(Request $request, ComicPage $page): JsonResponse
    {
        $this->assertPageOwnership($page, $request->user());

        if ($page->image_path) {
            Storage::disk('public')->delete($page->image_path);
        }
        $page->delete();

        return response()->json(['message' => 'Página eliminada.']);
    }

    /**
     * POST /api/me/works/{work}/pages/reorder
     * Body: { order: [pageId1, pageId2, ...] }
     */
    public function reorder(Request $request, Book $work): JsonResponse
    {
        $this->assertOwnership($work, $request->user());

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $ownIds = $work->comicPages()->pluck('id')->all();
        $valid = array_values(array_intersect($data['order'], $ownIds));

        DB::transaction(function () use ($valid) {
            foreach ($valid as $i => $id) {
                ComicPage::where('id', $id)->update(['position' => $i + 1]);
            }
        });

        return response()->json(['message' => 'Orden actualizado.']);
    }

    // ═══════════════ Páginas por CAPÍTULO (serie de cómic / webtoon) ═══════════════

    /** GET /api/me/chapters/{chapter}/pages */
    public function chapterIndex(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertChapterOwnership($chapter, $request->user());

        return response()->json([
            'data' => $chapter->comicPages()->get()->map(fn ($p) => $this->serialize($p)),
        ]);
    }

    /** POST /api/me/chapters/{chapter}/pages — imágenes sueltas del capítulo. */
    public function chapterStore(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertChapterOwnership($chapter, $request->user());

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:50'],
            'files.*' => ['file'],
        ]);

        $existing = (int) $chapter->comicPages()->count();
        if ($existing + count($request->file('files')) > FileGuard::MAX_COMIC_PAGES) {
            throw ValidationException::withMessages([
                'files' => ['El capítulo supera el máximo de '.FileGuard::MAX_COMIC_PAGES.' páginas.'],
            ]);
        }

        $guard = app(FileGuard::class);
        $position = (int) ($chapter->comicPages()->max('position') ?? 0);
        $created = [];

        foreach ($request->file('files') as $i => $file) {
            try {
                $ext = $guard->validateImage($file, "files.{$i}", FileGuard::COMIC_IMAGE_MAX_BYTES, FileGuard::MAX_IMAGE_DIM);
            } catch (ValidationException $e) {
                FileUploadAudit::record($request, 'comic_page', $file, 'rejected', collect($e->errors())->flatten()->first());
                throw $e;
            }

            $filename = 'pages/'.Str::random(40).'.'.$ext;
            $file->storeAs('', $filename, 'public');

            $position++;
            $page = ComicPage::create([
                'book_id' => $chapter->book_id,
                'chapter_id' => $chapter->id,
                'image_path' => $filename,
                'image_url' => null,
                'position' => $position,
                'size' => $file->getSize(),
            ]);
            try {
                $info = @getimagesize(Storage::disk('public')->path($filename));
                if ($info) {
                    $page->update(['width' => $info[0], 'height' => $info[1]]);
                }
            } catch (\Throwable) {
            }
            FileUploadAudit::record($request, 'comic_page', $file, 'accepted');
            $created[] = $this->serialize($page->fresh());
        }

        $this->autoPublishIfReady($chapter);

        return response()->json(['data' => $created, 'message' => count($created).' página(s) añadida(s).'], 201);
    }

    /** POST /api/me/chapters/{chapter}/pages/import — CBZ/ZIP → páginas del capítulo. */
    public function chapterImport(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertChapterOwnership($chapter, $request->user());

        $request->validate(['file' => ['required', 'file', 'max:1048576']]);

        $guard = app(FileGuard::class);
        $file = $request->file('file');
        $maxBytes = $guard->premiumWorkMaxBytes($request->user());

        try {
            $images = $guard->validateComicArchive($file, $maxBytes, 'file');
            $existing = (int) $chapter->comicPages()->count();
            if ($existing + count($images) > FileGuard::MAX_COMIC_PAGES) {
                throw ValidationException::withMessages(['file' => ['El capítulo supera el máximo de '.FileGuard::MAX_COMIC_PAGES.' páginas.']]);
            }
            $created = $this->storeArchiveImages($chapter->book, $chapter, $file->getRealPath(), $images);
            FileUploadAudit::record($request, 'comic_archive', $file, 'accepted', count($created).' páginas (capítulo)');
            $this->autoPublishIfReady($chapter);

            return response()->json(['data' => $created, 'message' => count($created).' página(s) importada(s).'], 201);
        } catch (ValidationException $e) {
            FileUploadAudit::record($request, 'comic_archive', $file, 'rejected', collect($e->errors())->flatten()->first());
            throw $e;
        }
    }

    /** POST /api/me/chapters/{chapter}/pages/reorder */
    public function chapterReorder(Request $request, Chapter $chapter): JsonResponse
    {
        $this->assertChapterOwnership($chapter, $request->user());

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $ownIds = $chapter->comicPages()->pluck('id')->all();
        $valid = array_values(array_intersect($data['order'], $ownIds));

        DB::transaction(function () use ($valid) {
            foreach ($valid as $i => $id) {
                ComicPage::where('id', $id)->update(['position' => $i + 1]);
            }
        });

        return response()->json(['message' => 'Orden actualizado.']);
    }

    /**
     * POST /api/me/works/{work}/chapters/import-comic
     * Auto-detecta capítulos desde UN solo ZIP/CBZ: cada carpeta de primer nivel
     * se vuelve un capítulo con sus imágenes (en orden). Sin carpetas → un capítulo.
     */
    public function importComicSeries(Request $request, Book $work): JsonResponse
    {
        $this->assertOwnership($work, $request->user());

        $request->validate(['file' => ['required', 'file', 'max:1048576']]);

        $guard = app(FileGuard::class);
        $file = $request->file('file');
        $maxBytes = $guard->premiumWorkMaxBytes($request->user());

        try {
            $images = $guard->validateComicArchive($file, $maxBytes, 'file');
        } catch (ValidationException $e) {
            FileUploadAudit::record($request, 'comic_archive', $file, 'rejected', collect($e->errors())->flatten()->first());
            throw $e;
        }

        // Agrupar por carpeta de primer nivel (orden natural de carpetas).
        $groups = [];
        foreach ($images as $img) {
            $slash = strpos($img['name'], '/');
            $folder = $slash !== false ? substr($img['name'], 0, $slash) : '';
            $groups[$folder][] = $img;
        }
        uksort($groups, 'strnatcasecmp');

        if (count($groups) > 300) {
            throw ValidationException::withMessages(['file' => ['El archivo tiene demasiados capítulos (máx. 300 carpetas).']]);
        }

        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return response()->json(['message' => 'No se pudo abrir el archivo del cómic.'], 422);
        }

        $number = (int) ($work->chapters()->max('number') ?? 0);
        $position = (int) ($work->chapters()->max('position') ?? 0);
        $chaptersCreated = [];

        foreach ($groups as $folder => $groupImages) {
            $number++;
            $position++;
            $chapter = $work->chapters()->create([
                'title' => $folder !== '' ? $folder : "Capítulo {$number}",
                'number' => $number,
                'position' => $position,
                'is_published' => (bool) $work->is_published,
            ]);

            $pagePos = 0;
            foreach ($groupImages as $img) {
                $bytes = $zip->getFromName($img['name']);
                if ($bytes === false) {
                    continue;
                }
                $filename = 'pages/'.Str::random(40).'.'.$img['ext'];
                $size = strlen($bytes);
                Storage::disk('public')->put($filename, $bytes);
                unset($bytes);
                $pagePos++;
                ComicPage::create([
                    'book_id' => $work->id,
                    'chapter_id' => $chapter->id,
                    'image_path' => $filename,
                    'image_url' => null,
                    'position' => $pagePos,
                    'size' => $size,
                    'width' => $img['width'],
                    'height' => $img['height'],
                ]);
            }
            $chaptersCreated[] = ['id' => $chapter->id, 'title' => $chapter->title, 'pages' => $pagePos];
        }
        $zip->close();

        FileUploadAudit::record($request, 'comic_archive', $file, 'accepted', count($chaptersCreated).' capítulos auto-detectados');

        return response()->json([
            'data' => $chaptersCreated,
            'message' => count($chaptersCreated).' capítulo(s) creado(s) con '.count($images).' página(s).',
        ], 201);
    }

    private function assertOwnership(Book $work, $user): void
    {
        if ($work->creator_id !== $user->id) {
            abort(403, 'Esta obra no es tuya.');
        }
    }

    private function assertPageOwnership(ComicPage $page, $user): void
    {
        $page->loadMissing('book');
        if ($page->book->creator_id !== $user->id) {
            abort(403, 'Esta página no es tuya.');
        }
    }

    private function assertChapterOwnership(Chapter $chapter, $user): void
    {
        $chapter->loadMissing('book');
        if (! $chapter->book) {
            abort(404, 'La obra de este capítulo ya no existe.');
        }
        if ($chapter->book->creator_id !== $user->id) {
            abort(403, 'Este capítulo no es tuyo.');
        }
    }

    /** Publica el capítulo si la obra ya está publicada y no está programado. */
    private function autoPublishIfReady(Chapter $chapter): void
    {
        if (! $chapter->is_published && $chapter->book?->is_published && ! $chapter->schedule_at) {
            $chapter->update(['is_published' => true]);
        }
    }

    private function serialize(ComicPage $p): array
    {
        return [
            'id' => $p->id,
            'book_id' => $p->book_id,
            'chapter_id' => $p->chapter_id,
            'image_url' => $p->image_link,
            'position' => (int) $p->position,
            'size' => $p->size,
            'width' => $p->width,
            'height' => $p->height,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
