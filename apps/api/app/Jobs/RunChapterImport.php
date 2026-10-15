<?php

namespace App\Jobs;

use App\Models\ChapterImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Crea los capítulos reales a partir del contenido ya parseado.
 *
 * - Numera continuando tras el último capítulo existente (coexiste con los
 *   capítulos creados a mano o por adjunto).
 * - Crea con Eloquent directo: NO dispara la notificación por-capítulo del
 *   controlador, evitando una avalancha al importar 100+ capítulos.
 * - Progreso por lotes (visible mientras corre). `tries=1`: ante fallo deja la
 *   importación en `failed` con los capítulos ya creados (el creador decide).
 */
class RunChapterImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    private const CHUNK = 25;

    public function __construct(public int $importId) {}

    public function handle(): void
    {
        $import = ChapterImport::find($this->importId);
        // Guard de idempotencia: solo procede desde "importing".
        if (! $import || $import->status !== ChapterImport::STATUS_IMPORTING) {
            return;
        }

        $import->update(['started_at' => now()]);

        $book = $import->book;
        if (! $book) {
            $this->fail($import, 'La obra ya no existe.');

            return;
        }

        $full = $this->loadParsed($import);
        if ($full === null) {
            $this->fail($import, 'No se encontró el contenido analizado.');

            return;
        }

        $publish = (bool) ($import->options['publish'] ?? false);
        $total = count($full);
        $base = (int) ($book->chapters()->max('number') ?? 0);
        $basePos = (int) ($book->chapters()->max('position') ?? 0);

        $created = 0;
        $words = 0;
        $warnings = 0;
        $errors = 0;

        $import->update(['total' => $total, 'progress' => 0]);

        try {
            foreach (array_chunk($full, self::CHUNK) as $chunk) {
                foreach ($chunk as $item) {
                    try {
                        // Nunca auto-publicar un capítulo sin texto real.
                        $hasBody = trim((string) ($item['body'] ?? '')) !== '';
                        $book->chapters()->create([
                            'title' => $item['title'],
                            'body' => $item['body'],
                            'number' => $base + $created + 1,
                            'position' => $basePos + $created + 1,
                            'is_published' => $publish && $hasBody,
                        ]);
                        $created++;
                        $words += (int) ($item['word_count'] ?? 0);
                        if (! empty($item['warning'])) {
                            $warnings++;
                        }
                    } catch (\Throwable $e) {
                        report($e);
                        $errors++;
                    }
                }
                // Progreso comprometido (visible para el polling del frontend).
                $import->update(['progress' => $created + $errors, 'created_count' => $created]);
            }
        } catch (\Throwable $e) {
            report($e);
            $this->fail($import, 'Ocurrió un error durante la importación.');

            return;
        }

        $import->update([
            'status' => ChapterImport::STATUS_DONE,
            'progress' => $total,
            'created_count' => $created,
            'word_count' => $words,
            'warning_count' => $warnings,
            'error_count' => $errors,
            'finished_at' => now(),
        ]);

        $this->cleanup($import);
    }

    public function failed(\Throwable $e): void
    {
        ChapterImport::where('id', $this->importId)->update([
            'status' => ChapterImport::STATUS_FAILED,
            'error' => 'Error inesperado durante la importación.',
            'finished_at' => now(),
        ]);
    }

    private function loadParsed(ChapterImport $import): ?array
    {
        if (! $import->parsed_path || ! Storage::disk('local')->exists($import->parsed_path)) {
            return null;
        }
        $data = json_decode(Storage::disk('local')->get($import->parsed_path), true);

        return is_array($data) ? $data : null;
    }

    private function fail(ChapterImport $import, string $message): void
    {
        $import->update([
            'status' => ChapterImport::STATUS_FAILED,
            'error' => $message,
            'finished_at' => now(),
        ]);
    }

    private function cleanup(ChapterImport $import): void
    {
        foreach ([$import->source_path, $import->parsed_path] as $path) {
            if ($path && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
