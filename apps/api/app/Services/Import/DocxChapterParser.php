<?php

namespace App\Services\Import;

use App\Services\Import\Concerns\DetectsChapters;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;

/**
 * Detecta capítulos en un .docx (texto plano, Fase A — sin HTML ni imágenes).
 *
 * Límite de capítulo por (en orden de prioridad):
 *   1. Encabezado de estilo: elemento Title (Heading 1/2) o párrafo con estilo
 *      "Heading 1/2" / "Título".
 *   2. Patrón de texto: "Capítulo 1", "Chapter 1", "CAPÍTULO I", romanos…
 *
 * Sin encabezados → un único capítulo con un aviso claro en la vista previa.
 * La seguridad del archivo (MIME, magic bytes, anti zip-bomb) ya la aplicó
 * FileGuard::validateImport antes de llegar aquí.
 */
class DocxChapterParser implements ChapterParser
{
    use DetectsChapters;

    public function supports(string $ext): bool
    {
        return $ext === 'docx';
    }

    /** @return ParsedChapter[] */
    public function parse(string $absolutePath): array
    {
        $phpWord = IOFactory::load($absolutePath, 'Word2007');

        /** @var array<int,array{title:?string,lines:string[]}> $blocks */
        $blocks = [];
        $current = ['title' => null, 'lines' => []];
        $anyHeading = false;

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                [$isHeading, $title, $text] = $this->classify($element);

                if ($isHeading) {
                    if ($this->blockHasContent($current)) {
                        $blocks[] = $current;
                    }
                    $current = ['title' => $this->cleanTitle((string) $title), 'lines' => []];
                    $anyHeading = true;
                } elseif ($text !== null && trim($text) !== '') {
                    $current['lines'][] = $text;
                }
            }
        }
        if ($this->blockHasContent($current)) {
            $blocks[] = $current;
        }

        $chapters = [];
        foreach ($blocks as $block) {
            // Párrafos separados por línea en blanco → lectura limpia.
            $body = $this->cleanBody(implode("\n\n", $block['lines']));
            $title = $block['title'];
            if ($title === null) {
                $title = $anyHeading ? 'Introducción' : 'Capítulo 1';
            }
            $warning = null;
            if (! $anyHeading) {
                // Documento sin encabezados → avisa en el preview.
                $warning = 'No se detectaron encabezados; se importó como un solo capítulo.';
            }
            $chapters[] = $this->makeChapter($title, $body, $warning);
        }

        return $chapters;
    }

    /**
     * Clasifica un elemento del documento.
     *
     * @return array{0:bool,1:?string,2:?string} [esEncabezado, títuloDetectado, textoCuerpo]
     */
    private function classify(object $element): array
    {
        // 1) Encabezado de estilo (Heading 1/2). Más profundo → cuerpo.
        if ($element instanceof Title) {
            $text = $this->elementText($element);
            $depth = (int) $element->getDepth();
            if ($depth <= 2 && $text !== null && trim($text) !== '') {
                return [true, $text, null];
            }

            return [false, null, $text];
        }

        $text = $this->elementText($element);
        if ($text === null) {
            return [false, null, null];
        }

        $trimmed = trim($text);
        if ($trimmed !== '' && ($this->isHeadingStyle($this->paragraphStyleName($element)) || $this->looksLikeHeading($trimmed))) {
            return [true, $trimmed, null];
        }

        return [false, null, $text];
    }

    /** Extrae texto plano de los distintos elementos de PhpWord. */
    private function elementText(object $element): ?string
    {
        if ($element instanceof TextRun) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $childText = $this->elementText($child);
                if ($childText !== null) {
                    $parts[] = $childText;
                }
            }

            return implode('', $parts);
        }

        if ($element instanceof Table) {
            $parts = [];
            foreach ($element->getRows() as $row) {
                foreach ($row->getCells() as $cell) {
                    foreach ($cell->getElements() as $cellElement) {
                        $cellText = $this->elementText($cellElement);
                        if ($cellText !== null && trim($cellText) !== '') {
                            $parts[] = $cellText;
                        }
                    }
                }
            }

            return implode(' ', $parts);
        }

        if ($element instanceof ListItem) {
            return $element->getTextObject()?->getText() ?? '';
        }

        // Text, Title, Link, etc. exponen getText() (string u objeto anidado).
        if (method_exists($element, 'getText')) {
            $value = $element->getText();
            if (is_string($value)) {
                return $value;
            }
            if (is_object($value)) {
                return $this->elementText($value);
            }
        }

        return null;
    }

    /** Nombre del estilo de párrafo, si el elemento lo expone. */
    private function paragraphStyleName(object $element): ?string
    {
        if (! method_exists($element, 'getParagraphStyle')) {
            return null;
        }
        $style = $element->getParagraphStyle();
        if (is_string($style)) {
            return $style;
        }
        if (is_object($style) && method_exists($style, 'getStyleName')) {
            return $style->getStyleName();
        }

        return null;
    }

    /** ¿El nombre de estilo corresponde a un encabezado de nivel 1/2 o título? */
    private function isHeadingStyle(?string $name): bool
    {
        if (! $name) {
            return false;
        }

        return (bool) preg_match('/^(heading\s*[12]\b|t[íi]tulo\s*[12]?\b|title)/i', $name);
    }
}
