<?php

namespace Tests\Unit;

use App\Services\Import\ParsedChapter;
use App\Services\Import\TxtChapterParser;
use PHPUnit\Framework\TestCase;

class TxtChapterParserTest extends TestCase
{
    /** @return ParsedChapter[] */
    private function parseText(string $text): array
    {
        $path = tempnam(sys_get_temp_dir(), 'txtimport');
        file_put_contents($path, $text);
        $chapters = (new TxtChapterParser)->parse($path);
        @unlink($path);

        return $chapters;
    }

    public function test_detecta_capitulos_por_palabra_clave(): void
    {
        $chapters = $this->parseText(
            "Capítulo 1\nEl comienzo de todo.\n\nCapítulo 2\nLa aventura sigue.\n\nCapítulo 3\nEl final."
        );

        $this->assertCount(3, $chapters);
        $this->assertSame('Capítulo 1', $chapters[0]->title);
        $this->assertSame('El comienzo de todo.', $chapters[0]->body);
        $this->assertSame('La aventura sigue.', $chapters[1]->body);
    }

    public function test_acepta_variantes_chapter_sin_acento_y_romano(): void
    {
        $chapters = $this->parseText(
            "Capitulo 1\nUno.\n\nChapter 2\nDos.\n\nCAPÍTULO III\nTres."
        );

        $this->assertCount(3, $chapters);
        $this->assertSame('CAPÍTULO III', $chapters[2]->title);
    }

    public function test_conserva_titulo_completo_con_subtitulo(): void
    {
        $chapters = $this->parseText("Capítulo 1: El despertar\nTexto.");
        $this->assertCount(1, $chapters);
        $this->assertSame('Capítulo 1: El despertar', $chapters[0]->title);
    }

    public function test_sin_encabezados_crea_un_solo_capitulo(): void
    {
        $chapters = $this->parseText("Solo texto corrido.\nSin títulos.\nNada de capítulos.");
        $this->assertCount(1, $chapters);
        $this->assertSame('Capítulo 1', $chapters[0]->title);
    }

    public function test_contenido_previo_al_primer_encabezado_es_introduccion(): void
    {
        $chapters = $this->parseText("Prólogo del autor.\n\nCapítulo 1\nContenido.");
        $this->assertCount(2, $chapters);
        $this->assertSame('Introducción', $chapters[0]->title);
        $this->assertSame('Prólogo del autor.', $chapters[0]->body);
    }

    public function test_no_confunde_palabra_con_romano_minuscula(): void
    {
        // "civil" son letras romanas en minúscula: NO debe tratarse como encabezado.
        $chapters = $this->parseText('Capítulo civil de la nación y sus leyes vigentes hoy.');
        $this->assertCount(1, $chapters);
        $this->assertSame('Capítulo 1', $chapters[0]->title);
    }

    public function test_cuenta_palabras_y_marca_capitulo_vacio(): void
    {
        $chapters = $this->parseText("Capítulo 1\nuna dos tres\n\nCapítulo 2\n");
        $this->assertSame(3, $chapters[0]->wordCount);
        $this->assertNull($chapters[0]->warning);
        $this->assertSame(0, $chapters[1]->wordCount);
        $this->assertNotNull($chapters[1]->warning);
    }
}
