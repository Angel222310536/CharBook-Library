<?php

namespace App\Services\Import;

/**
 * Contrato de un parser de importación. Cada formato (TXT/DOCX/EPUB) implementa
 * esta interfaz; el `ImportManager` resuelve el adecuado por extensión.
 */
interface ChapterParser
{
    /** ¿Este parser maneja la extensión dada? (p. ej. "txt"). */
    public function supports(string $ext): bool;

    /**
     * Analiza el archivo y devuelve los capítulos detectados, en orden.
     *
     * @return ParsedChapter[]
     */
    public function parse(string $absolutePath): array;
}
