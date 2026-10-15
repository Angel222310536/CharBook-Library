<?php

namespace Tests\Unit;

use App\Services\Import\DocxChapterParser;
use App\Services\Import\ParsedChapter;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

class DocxChapterParserTest extends TestCase
{
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function makeDocx(callable $build): string
    {
        $word = new PhpWord;
        // Registrar los estilos de título hace que el writer emita pStyle
        // "Heading1/2" (igual que un .docx real de Word) y que el reader los
        // devuelva como elementos Title.
        $word->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $word->addTitleStyle(2, ['bold' => true, 'size' => 14]);
        $section = $word->addSection();
        $build($section);
        $path = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $this->tmp[] = $path;

        return $path;
    }

    /** @return ParsedChapter[] */
    private function parse(string $path): array
    {
        return (new DocxChapterParser)->parse($path);
    }

    public function test_detecta_por_heading_1(): void
    {
        // Títulos que NO coinciden con el patrón de texto → prueba el estilo.
        $path = $this->makeDocx(function ($s) {
            $s->addTitle('El Comienzo', 1);
            $s->addText('Contenido del primer capítulo.');
            $s->addTitle('La Travesía', 1);
            $s->addText('Contenido del segundo capítulo.');
        });

        $chapters = $this->parse($path);

        $this->assertCount(2, $chapters);
        $this->assertSame('El Comienzo', $chapters[0]->title);
        $this->assertSame('La Travesía', $chapters[1]->title);
        $this->assertStringContainsString('primer capítulo', $chapters[0]->body);
    }

    public function test_detecta_por_heading_2(): void
    {
        $path = $this->makeDocx(function ($s) {
            $s->addTitle('Nivel Dos', 2);
            $s->addText('Cuerpo bajo un Heading 2.');
        });

        $chapters = $this->parse($path);

        $this->assertCount(1, $chapters);
        $this->assertSame('Nivel Dos', $chapters[0]->title);
    }

    public function test_detecta_por_patron_de_texto_sin_estilo(): void
    {
        $path = $this->makeDocx(function ($s) {
            $s->addText('Capítulo 1');
            $s->addText('Primer cuerpo.');
            $s->addText('Capítulo 2');
            $s->addText('Segundo cuerpo.');
        });

        $chapters = $this->parse($path);

        $this->assertCount(2, $chapters);
        $this->assertSame('Capítulo 1', $chapters[0]->title);
        $this->assertSame('Primer cuerpo.', $chapters[0]->body);
    }

    public function test_sin_encabezados_un_solo_capitulo_con_aviso(): void
    {
        $path = $this->makeDocx(function ($s) {
            $s->addText('Solo texto corrido.');
            $s->addText('Sin ningún título ni patrón.');
        });

        $chapters = $this->parse($path);

        $this->assertCount(1, $chapters);
        $this->assertSame('Capítulo 1', $chapters[0]->title);
        $this->assertNotNull($chapters[0]->warning);
        $this->assertStringContainsString('encabezados', $chapters[0]->warning);
    }

    public function test_extrae_texto_de_textrun_con_varios_runs(): void
    {
        $path = $this->makeDocx(function ($s) {
            $s->addTitle('Capítulo 1', 1);
            $run = $s->addTextRun();
            $run->addText('Hola ');
            $run->addText('mundo ');
            $run->addText('unido.');
        });

        $chapters = $this->parse($path);

        $this->assertCount(1, $chapters);
        $this->assertSame('Hola mundo unido.', $chapters[0]->body);
        $this->assertSame(3, $chapters[0]->wordCount);
    }
}
