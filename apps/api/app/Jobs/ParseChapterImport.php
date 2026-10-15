<?php

namespace App\Jobs;

use App\Models\ChapterImport;
use App\Services\Import\ImportManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Analiza el archivo subido y deja la importación en `preview_ready` con la
 * vista previa (títulos/conteos) + el contenido parseado en disco para que
 * "confirmar" no tenga que re-parsear. Va en cola: parsear en el request HTTP
 * provocaría timeouts con archivos grandes.
 */
class ParseChapterImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $importId) {}

    public function handle(ImportManager $manager): void
    {
        $import = ChapterImport::find($this->importId);
        if (! $import || $import->status === ChapterImport::STATUS_CANCELED) {
            return;
        }

        $import->update(['status' => ChapterImport::STATUS_PARSING]);

        try {
            if (! $import->source_path || ! Storage::disk('local')->exists($import->source_path)) {
                throw new \RuntimeException('Archivo de origen no encontrado.');
            }

            $absPath = Storage::disk('local')->path($import->source_path);
            $chapters = $manager->parse($absPath, (string) $import->source_ext);

            if (empty($chapters)) {
                $import->update([
                    'status' => ChapterImport::STATUS_FAILED,
                    'error' => 'No se detectó contenido legible en el archivo.',
                ]);

                return;
            }

            $detected = [];
            $full = [];
            $totalWords = 0;
            $warnings = 0;

            foreach ($chapters as $i => $chapter) {
                $detected[] = $chapter->toPreview($i + 1);
                $full[] = [
                    'title' => $chapter->title,
                    'body' => $chapter->body,
                    'word_count' => $chapter->wordCount,
                    'warning' => $chapter->warning,
                ];
                $totalWords += $chapter->wordCount;
                if ($chapter->warning) {
                    $warnings++;
                }
            }

            $parsedPath = 'imports/parsed/'.Str::random(40).'.json';
            Storage::disk('local')->put(
                $parsedPath,
                json_encode($full, JSON_UNESCAPED_UNICODE) ?: '[]',
            );

            $import->update([
                'status' => ChapterImport::STATUS_PREVIEW_READY,
                'detected' => $detected,
                'parsed_path' => $parsedPath,
                'total' => count($chapters),
                'word_count' => $totalWords,
                'warning_count' => $warnings,
            ]);
        } catch (\Throwable $e) {
            report($e);
            $import->update([
                'status' => ChapterImport::STATUS_FAILED,
                'error' => 'No se pudo analizar el archivo. Revisa que el formato sea válido.',
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        ChapterImport::where('id', $this->importId)->update([
            'status' => ChapterImport::STATUS_FAILED,
            'error' => 'Error inesperado al analizar el archivo.',
        ]);
    }
}
