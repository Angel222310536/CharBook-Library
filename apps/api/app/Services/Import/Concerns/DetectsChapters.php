<?php

namespace App\Services\Import\Concerns;

use App\Services\Import\ParsedChapter;

/**
 * Lógica compartida por los parsers de importación (TXT, DOCX, …):
 * detección de encabezados por patrón, normalización y construcción de
 * `ParsedChapter` (texto plano, con avisos de vacío/recorte y conteo de
 * palabras). Mantiene un comportamiento idéntico entre formatos.
 */
trait DetectsChapters
{
    private const MAX_BODY_CHARS = 500000;  // alineado con la validación manual

    private const MAX_TITLE_CHARS = 200;    // chapters.title es varchar(200)

    private const MAX_HEADING_LINE = 100;   // una línea más larga no es un título

    /** Keyword case-insensitive (?i:), numeral árabe o romano EN MAYÚSCULA. */
    private const HEADING_RE = '/^\s*(?i:cap[íi]tulo|capitulo|chapter|cap\.)\s*(\d{1,4}|[IVXLCDM]{1,12})\b.*$/u';

    /** ¿La línea/párrafo parece un encabezado de capítulo por su texto? */
    protected function looksLikeHeading(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_HEADING_LINE) {
            return false;
        }

        return (bool) preg_match(self::HEADING_RE, $trimmed);
    }

    /** @param array{title:?string,lines:string[]} $block */
    protected function blockHasContent(array $block): bool
    {
        return $block['title'] !== null || trim(implode("\n", $block['lines'])) !== '';
    }

    protected function makeChapter(string $title, string $body, ?string $warning = null): ParsedChapter
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title === '') {
            $title = 'Capítulo';
        }
        if (mb_strlen($title) > self::MAX_TITLE_CHARS) {
            $title = mb_substr($title, 0, self::MAX_TITLE_CHARS - 1).'…';
        }

        if (mb_strlen($body) > self::MAX_BODY_CHARS) {
            $body = mb_substr($body, 0, self::MAX_BODY_CHARS);
            $warning = 'Capítulo muy largo: se recortó al límite permitido.';
        }
        if (trim($body) === '') {
            $warning = 'Capítulo sin contenido de texto.';
        }

        return new ParsedChapter($title, $body, $this->wordCount($body), $warning);
    }

    protected function cleanTitle(string $line): string
    {
        return trim(preg_replace('/\s+/u', ' ', $line) ?? '');
    }

    protected function cleanBody(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Colapsa 3+ saltos en 2 → párrafos limpios para el lector actual.
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    protected function wordCount(string $text): int
    {
        if (trim($text) === '') {
            return 0;
        }

        return (int) preg_match_all('/\S+/u', $text);
    }
}
