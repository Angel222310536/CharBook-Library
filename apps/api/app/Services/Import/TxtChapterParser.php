<?php

namespace App\Services\Import;

use App\Services\Import\Concerns\DetectsChapters;

/**
 * Detecta capítulos en un .txt por patrones de encabezado:
 *   Capítulo 1 · Capitulo 1 · Chapter 1 · CAPÍTULO I · Cap. 3: Título …
 *
 * Números árabes o romanos EN MAYÚSCULA (el romano en minúscula provocaría
 * falsos positivos con palabras como "civil"). El texto antes del primer
 * encabezado se guarda como "Introducción". Si no hay encabezados, todo el
 * archivo es un único capítulo.
 */
class TxtChapterParser implements ChapterParser
{
    use DetectsChapters;

    public function supports(string $ext): bool
    {
        return $ext === 'txt';
    }

    /** @return ParsedChapter[] */
    public function parse(string $absolutePath): array
    {
        $raw = @file_get_contents($absolutePath);
        if ($raw === false || $raw === '') {
            return [];
        }

        // Normaliza BOM y saltos de línea.
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = explode("\n", $normalized);

        /** @var array<int,array{title:?string,lines:string[]}> $blocks */
        $blocks = [];
        $current = ['title' => null, 'lines' => []];
        $anyHeading = false;

        foreach ($lines as $line) {
            if ($this->looksLikeHeading($line)) {
                if ($this->blockHasContent($current)) {
                    $blocks[] = $current;
                }
                $current = ['title' => $this->cleanTitle($line), 'lines' => []];
                $anyHeading = true;
            } else {
                $current['lines'][] = $line;
            }
        }
        if ($this->blockHasContent($current)) {
            $blocks[] = $current;
        }

        $chapters = [];
        foreach ($blocks as $block) {
            $body = $this->cleanBody(implode("\n", $block['lines']));
            $title = $block['title'];
            if ($title === null) {
                // Sin título: contenido previo al primer encabezado → "Introducción";
                // pero si el documento NO tiene encabezados, es el capítulo único.
                $title = $anyHeading ? 'Introducción' : 'Capítulo 1';
            }
            $chapters[] = $this->makeChapter($title, $body);
        }

        // Sin encabezados detectados → un único capítulo con todo el texto.
        if (empty($chapters)) {
            $body = $this->cleanBody($normalized);
            if ($body !== '') {
                $chapters[] = $this->makeChapter('Capítulo 1', $body);
            }
        }

        return $chapters;
    }
}
