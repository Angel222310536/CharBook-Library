<?php

namespace App\Services\Import;

/**
 * Resuelve el parser adecuado por extensión y ejecuta el análisis.
 *
 * Fase 1 registra solo TXT. Al añadir DOCX (Fase 2) y EPUB (Fase 3) basta con
 * registrar su parser aquí; el resto del flujo (endpoints, jobs, UI) no cambia.
 */
class ImportManager
{
    /** @var ChapterParser[] */
    private array $parsers;

    public function __construct()
    {
        $this->parsers = [
            new TxtChapterParser,
            new DocxChapterParser,
            new EpubChapterParser,
        ];
    }

    /** ¿Hay un parser registrado para esta extensión? */
    public function supports(string $ext): bool
    {
        return $this->parserFor($ext) !== null;
    }

    public function parserFor(string $ext): ?ChapterParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($ext)) {
                return $parser;
            }
        }

        return null;
    }

    /**
     * Analiza el archivo y devuelve los capítulos detectados.
     *
     * @return ParsedChapter[]
     */
    public function parse(string $absolutePath, string $ext): array
    {
        $parser = $this->parserFor($ext);
        if (! $parser) {
            throw new \RuntimeException("No hay parser disponible para .{$ext}");
        }

        return $parser->parse($absolutePath);
    }
}
