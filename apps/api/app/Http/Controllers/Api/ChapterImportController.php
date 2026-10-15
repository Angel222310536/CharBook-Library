<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ParseChapterImport;
use App\Jobs\RunChapterImport;
use App\Models\Book;
use App\Models\ChapterImport;
use App\Services\FileGuard;
use App\Services\Import\ImportManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Importador de libros (EPUB/DOCX/TXT) → capítulos reales.
 *
 * Flujo: subir (parsea en cola) → previsualizar → confirmar (crea en cola).
 * Sistema NUEVO y aislado: no toca capítulos existentes ni el adjunto de
 * archivos. Reutiliza FileGuard para toda la seguridad de subida.
 */
class ChapterImportController extends Controller
{
    /** POST /api/me/works/{work}/imports — sube y dispara el análisis. */
    public function store(Request $request, Book $work, FileGuard $guard, ImportManager $manager): JsonResponse
    {
        $this->assertOwnership($work, $request->user());

        $request->validate([
            // Tope general 1 GB; FileGuard afina por tipo y por PLAN (TXT 5 · DOCX 25 ·
            // EPUB 50 MB; premium sube EPUB a 500 MB / 1 GB).
            'file' => ['required', 'file', 'max:1048576'],
        ]);

        $file = $request->file('file');

        // Seguridad completa: MIME real + magic bytes + blocklist + tamaño por
        // tipo/plan + anti zip-bomb (EPUB/DOCX). Lanza 422 si algo no cuadra.
        $ext = $guard->validateImport($file, 'file', $request->user());

        // Fase 1: solo TXT tiene parser. DOCX/EPUB llegan en fases siguientes.
        if (! $manager->supports($ext)) {
            return response()->json([
                'message' => 'Por ahora solo se pueden importar archivos TXT. DOCX y EPUB estarán disponibles próximamente.',
                'errors' => ['file' => ['Formato aún no soportado por el importador.']],
            ], 422);
        }

        // Guardado en disco PRIVADO (no público): es material temporal de trabajo.
        $path = 'imports/'.Str::random(40).'.'.$ext;
        $file->storeAs('', $path, 'local');

        $import = ChapterImport::create([
            'book_id' => $work->id,
            'user_id' => $request->user()->id,
            'status' => ChapterImport::STATUS_UPLOADED,
            'source_name' => $file->getClientOriginalName(),
            'source_ext' => $ext,
            'source_path' => $path,
            'source_size' => $file->getSize(),
        ]);

        // Síncrono: el análisis corre dentro del request (no depende de que
        // haya un worker de cola activo). Los archivos están acotados por tamaño
        // y `php artisan serve` (CLI) no tiene límite de ejecución.
        ParseChapterImport::dispatchSync($import->id);

        return response()->json(['data' => $this->serialize($import->fresh())], 201);
    }

    /** GET /api/me/works/{work}/imports/{import} — estado + vista previa (polling). */
    public function show(Request $request, Book $work, ChapterImport $import): JsonResponse
    {
        $this->assertOwnership($work, $request->user());
        if ($import->book_id !== $work->id) {
            abort(404, 'Importación no encontrada para esta obra.');
        }

        return response()->json(['data' => $this->serialize($import)]);
    }

    /** POST /api/me/imports/{import}/confirm — crea los capítulos. */
    public function confirm(Request $request, ChapterImport $import): JsonResponse
    {
        $this->assertImportOwnership($import, $request->user());

        if ($import->status !== ChapterImport::STATUS_PREVIEW_READY) {
            return response()->json([
                'message' => 'La importación no está lista para confirmar.',
            ], 422);
        }

        $data = $request->validate([
            'publish' => ['sometimes', 'boolean'],
        ]);

        $import->update([
            'status' => ChapterImport::STATUS_IMPORTING,
            'options' => ['publish' => (bool) ($data['publish'] ?? false)],
            'progress' => 0,
            'error' => null,
        ]);

        // Síncrono: la creación corre dentro del request (sin depender de un
        // worker de cola). El front muestra el resumen al volver.
        RunChapterImport::dispatchSync($import->id);

        return response()->json(['data' => $this->serialize($import->fresh())]);
    }

    /** DELETE /api/me/imports/{import} — cancela/limpia. */
    public function destroy(Request $request, ChapterImport $import): JsonResponse
    {
        $this->assertImportOwnership($import, $request->user());

        foreach ([$import->source_path, $import->parsed_path] as $path) {
            if ($path && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
        $import->delete();

        return response()->json(['message' => 'Importación eliminada.']);
    }

    private function assertOwnership(Book $work, $user): void
    {
        if ($work->creator_id !== $user->id) {
            abort(403, 'Esta obra no es tuya.');
        }
    }

    private function assertImportOwnership(ChapterImport $import, $user): void
    {
        $import->loadMissing('book');
        if (! $import->book) {
            abort(404, 'La obra de esta importación ya no existe.');
        }
        if ($import->book->creator_id !== $user->id) {
            abort(403, 'Esta importación no es tuya.');
        }
    }

    private function serialize(ChapterImport $i): array
    {
        return [
            'id' => $i->id,
            'book_id' => $i->book_id,
            'status' => $i->status,
            'source_name' => $i->source_name,
            'source_ext' => $i->source_ext,
            'source_size' => $i->source_size ? (int) $i->source_size : null,
            'detected' => $i->detected,
            'total' => (int) $i->total,
            'progress' => (int) $i->progress,
            'created_count' => (int) $i->created_count,
            'warning_count' => (int) $i->warning_count,
            'error_count' => (int) $i->error_count,
            'word_count' => (int) $i->word_count,
            'error' => $i->error,
            'started_at' => $i->started_at?->toIso8601String(),
            'finished_at' => $i->finished_at?->toIso8601String(),
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
