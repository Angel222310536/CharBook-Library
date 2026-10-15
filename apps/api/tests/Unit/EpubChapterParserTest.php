<?php

namespace Tests\Unit;

use App\Services\Import\EpubChapterParser;
use App\Services\Import\ParsedChapter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Parser EPUB. Las muestras se construyen con ZipArchive imitando un EPUB real
 * (mimetype + container + OPF + spine + NCX/nav + XHTML). Extiende TestCase para
 * que FileGuard::loadXmlSafe (que usa ValidationException) tenga contenedor.
 */
class EpubChapterParserTest extends TestCase
{
    /** Párrafo > 100 caracteres reales (umbral mínimo de capítulo). */
    private const LONG = 'Este es un parrafo de contenido real con la longitud suficiente para superar el minimo de cien caracteres y contar como un capitulo legible.';

    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    /** @param array<string,string> $files */
    private function buildEpub(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'epub').'.epub';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $this->tmp[] = $path;

        return $path;
    }

    private function container(string $opf = 'OEBPS/content.opf'): string
    {
        return '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
            ."<rootfiles><rootfile full-path=\"{$opf}\" media-type=\"application/oebps-package+xml\"/></rootfiles></container>";
    }

    /** @param array<int,array{file:string,title:string}> $chapters */
    private function opf(array $chapters, bool $ncx): string
    {
        $items = $spine = '';
        foreach ($chapters as $i => $c) {
            $items .= "<item id=\"c{$i}\" href=\"{$c['file']}\" media-type=\"application/xhtml+xml\"/>";
            $spine .= "<itemref idref=\"c{$i}\"/>";
        }
        $toc = '';
        if ($ncx) {
            $items .= '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>';
            $toc = ' toc="ncx"';
        }

        return '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="b">'
            ."<metadata/><manifest>{$items}</manifest><spine{$toc}>{$spine}</spine></package>";
    }

    /** @param array<int,array{file:string,title:string}> $chapters */
    private function ncx(array $chapters): string
    {
        $points = '';
        foreach ($chapters as $i => $c) {
            $points .= "<navPoint id=\"n{$i}\" playOrder=\"{$i}\"><navLabel><text>{$c['title']}</text></navLabel>"
                ."<content src=\"{$c['file']}\"/></navPoint>";
        }

        return '<?xml version="1.0"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">'
            ."<navMap>{$points}</navMap></ncx>";
    }

    private function xhtml(string $title, string $body): string
    {
        return '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml">'
            ."<head><title>{$title}</title></head><body>{$body}</body></html>";
    }

    /** @return ParsedChapter[] */
    private function parse(string $path): array
    {
        return (new EpubChapterParser)->parse($path);
    }

    public function test_epub_con_toc_ncx_usa_titulos_del_toc(): void
    {
        // El <title> del XHTML difiere del NCX → si gana el NCX, lo probamos.
        $chapters = [
            ['file' => 'ch1.xhtml', 'title' => 'El Comienzo'],
            ['file' => 'ch2.xhtml', 'title' => 'La Travesía'],
        ];
        $path = $this->buildEpub([
            'META-INF/container.xml' => $this->container(),
            'OEBPS/content.opf' => $this->opf($chapters, true),
            'OEBPS/toc.ncx' => $this->ncx($chapters),
            'OEBPS/ch1.xhtml' => $this->xhtml('IGNORADO', '<p>Marca-uno. '.self::LONG.'</p>'),
            'OEBPS/ch2.xhtml' => $this->xhtml('IGNORADO', '<p>Marca-dos. '.self::LONG.'</p>'),
        ]);

        $result = $this->parse($path);

        $this->assertCount(2, $result);
        $this->assertSame('El Comienzo', $result[0]->title);
        $this->assertSame('La Travesía', $result[1]->title);
        $this->assertStringContainsString('Marca-uno.', $result[0]->body);
        $this->assertNull($result[0]->warning);
    }

    public function test_epub_sin_toc_infiere_titulos_y_avisa(): void
    {
        $chapters = [
            ['file' => 'ch1.xhtml', 'title' => 'x'],
            ['file' => 'ch2.xhtml', 'title' => 'x'],
        ];
        $path = $this->buildEpub([
            'META-INF/container.xml' => $this->container(),
            'OEBPS/content.opf' => $this->opf($chapters, false),
            'OEBPS/ch1.xhtml' => $this->xhtml('Capitulo Uno', '<p>'.self::LONG.'</p>'),
            'OEBPS/ch2.xhtml' => $this->xhtml('Capitulo Dos', '<p>'.self::LONG.'</p>'),
        ]);

        $result = $this->parse($path);

        $this->assertCount(2, $result);
        $this->assertSame('Capitulo Uno', $result[0]->title); // inferido del <title>
        $this->assertNotNull($result[0]->warning);
        $this->assertStringContainsString('índice', $result[0]->warning);
        $this->assertNull($result[1]->warning); // el aviso de TOC solo va en el 1.º
    }

    public function test_epub_varios_xhtml_respeta_orden_del_spine(): void
    {
        $chapters = [
            ['file' => 'a.xhtml', 'title' => 'Primero'],
            ['file' => 'b.xhtml', 'title' => 'Segundo'],
            ['file' => 'c.xhtml', 'title' => 'Tercero'],
        ];
        $path = $this->buildEpub([
            'META-INF/container.xml' => $this->container(),
            'OEBPS/content.opf' => $this->opf($chapters, true),
            'OEBPS/toc.ncx' => $this->ncx($chapters),
            'OEBPS/a.xhtml' => $this->xhtml('x', '<p>marca-uno '.self::LONG.'</p>'),
            'OEBPS/b.xhtml' => $this->xhtml('x', '<p>marca-dos '.self::LONG.'</p>'),
            'OEBPS/c.xhtml' => $this->xhtml('x', '<p>marca-tres '.self::LONG.'</p>'),
        ]);

        $result = $this->parse($path);

        $this->assertSame(['Primero', 'Segundo', 'Tercero'], array_map(fn ($c) => $c->title, $result));
        $this->assertStringContainsString('marca-uno', $result[0]->body);
        $this->assertStringContainsString('marca-tres', $result[2]->body);
    }

    public function test_xhtml_se_convierte_a_texto_seguro(): void
    {
        $chapters = [['file' => 'ch1.xhtml', 'title' => 'Seguro']];
        $body = '<script>alert("x")</script><style>.a{color:red}</style>'
            .'<p>Hola &amp; chau</p><p>'.self::LONG.'</p><img src="foto.jpg"/><p>Fin.</p>';
        $path = $this->buildEpub([
            'META-INF/container.xml' => $this->container(),
            'OEBPS/content.opf' => $this->opf($chapters, true),
            'OEBPS/toc.ncx' => $this->ncx($chapters),
            'OEBPS/ch1.xhtml' => $this->xhtml('Seguro', $body),
        ]);

        $result = $this->parse($path);

        $this->assertCount(1, $result);
        $this->assertStringNotContainsString('alert', $result[0]->body); // script eliminado
        $this->assertStringNotContainsString('color:red', $result[0]->body); // style eliminado
        $this->assertStringContainsString('Hola & chau', $result[0]->body); // entidad decodificada
        $this->assertStringContainsString('Fin.', $result[0]->body);
        $this->assertNotNull($result[0]->warning);
        $this->assertStringContainsString('imágenes', $result[0]->warning); // <img> avisado
    }

    public function test_omite_portada_indice_creditos_y_conserva_capitulo_real(): void
    {
        // OPF con nav (EPUB3), guide con cover, y spine: cover + titlepage +
        // copyright + nav + capítulo real. Solo el capítulo real debe quedar.
        $opf = '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="b"><metadata/>'
            .'<manifest>'
            .'<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"/>'
            .'<item id="tp" href="titlepage.xhtml" media-type="application/xhtml+xml"/>'
            .'<item id="cp" href="copyright.xhtml" media-type="application/xhtml+xml"/>'
            .'<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
            .'<item id="ch1" href="chapter1.xhtml" media-type="application/xhtml+xml"/>'
            .'<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
            .'</manifest>'
            .'<spine toc="ncx"><itemref idref="cover"/><itemref idref="tp"/><itemref idref="cp"/><itemref idref="nav"/><itemref idref="ch1"/></spine>'
            .'<guide><reference type="cover" href="cover.xhtml" title="Cover"/></guide></package>';

        $ncx = $this->ncx([
            ['file' => 'cover.xhtml', 'title' => 'Cover'],
            ['file' => 'titlepage.xhtml', 'title' => 'Title Page'],
            ['file' => 'copyright.xhtml', 'title' => 'Copyright'],
            ['file' => 'chapter1.xhtml', 'title' => 'Chapter 1'],
        ]);

        $path = $this->buildEpub([
            'META-INF/container.xml' => $this->container(),
            'OEBPS/content.opf' => $opf,
            'OEBPS/toc.ncx' => $ncx,
            'OEBPS/cover.xhtml' => $this->xhtml('Cover', '<img src="cover.jpg" alt="portada"/>'),
            'OEBPS/titlepage.xhtml' => $this->xhtml('Title Page', '<h1>Mi Libro</h1><p>por el Autor</p>'),
            'OEBPS/copyright.xhtml' => $this->xhtml('Copyright', '<p>Derechos reservados 2020.</p>'),
            'OEBPS/nav.xhtml' => $this->xhtml('Contents', '<nav><ol><li><a href="chapter1.xhtml">Chapter 1</a></li></ol></nav>'),
            'OEBPS/chapter1.xhtml' => $this->xhtml('Chapter 1', '<p>marca-real '.self::LONG.'</p>'),
        ]);

        $result = $this->parse($path);

        $this->assertCount(1, $result);
        $this->assertSame('Chapter 1', $result[0]->title);
        $this->assertStringContainsString('marca-real', $result[0]->body);
        $this->assertNotEmpty(trim($result[0]->body));
        // Ninguna página técnica se coló.
        $this->assertNotContains('Cover', array_map(fn ($c) => $c->title, $result));
        // Aviso de páginas omitidas en el capítulo conservado.
        $this->assertNotNull($result[0]->warning);
        $this->assertStringContainsString('omitieron', $result[0]->warning);
    }

    public function test_pagina_corta_no_capitulo_se_descarta(): void
    {
        $chapters = [
            ['file' => 'a.xhtml', 'title' => 'Nota'],
            ['file' => 'b.xhtml', 'title' => 'Capítulo Real'],
        ];
        $path = $this->buildEpub([
            'META-INF/container.xml' => $this->container(),
            'OEBPS/content.opf' => $this->opf($chapters, true),
            'OEBPS/toc.ncx' => $this->ncx($chapters),
            'OEBPS/a.xhtml' => $this->xhtml('Nota', '<p>Corto.</p>'), // < 100 chars, título no-capítulo
            'OEBPS/b.xhtml' => $this->xhtml('Capítulo Real', '<p>real '.self::LONG.'</p>'),
        ]);

        $result = $this->parse($path);

        $this->assertCount(1, $result);
        $this->assertSame('Capítulo Real', $result[0]->title);
    }

    public function test_extrae_imagenes_de_comic_en_orden_del_spine(): void
    {
        // PNG 1x1 válido (lo reconoce getimagesizefromstring sin GD).
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMCAQDJjz3xAAAAAElFTkSuQmCC');
        $opf = '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="b"><metadata/>'
            .'<manifest>'
            .'<item id="p1" href="page1.xhtml" media-type="application/xhtml+xml"/>'
            .'<item id="p2" href="page2.xhtml" media-type="application/xhtml+xml"/>'
            .'<item id="i1" href="img1.png" media-type="image/png"/>'
            .'<item id="i2" href="img2.png" media-type="image/png"/>'
            .'</manifest><spine><itemref idref="p1"/><itemref idref="p2"/></spine></package>';
        $path = $this->buildEpub([
            'META-INF/container.xml' => $this->container(),
            'OEBPS/content.opf' => $opf,
            'OEBPS/page1.xhtml' => '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body><img src="img1.png"/></body></html>',
            'OEBPS/page2.xhtml' => '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body><img src="img2.png"/></body></html>',
            'OEBPS/img1.png' => $png,
            'OEBPS/img2.png' => $png,
        ]);

        $images = (new EpubChapterParser)->extractComicImages($path);

        $this->assertCount(2, $images);
        $this->assertSame('png', $images[0]['ext']);
        $this->assertSame($png, $images[0]['bytes']);
    }

    public function test_epub_con_xxe_en_opf_no_se_expande(): void
    {
        $opfXxe = '<?xml version="1.0"?><!DOCTYPE package [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="b">'
            .'<metadata><dc:title>&xxe;</dc:title></metadata>'
            .'<manifest><item id="c0" href="ch1.xhtml" media-type="application/xhtml+xml"/></manifest>'
            .'<spine><itemref idref="c0"/></spine></package>';
        $path = $this->buildEpub([
            'META-INF/container.xml' => $this->container(),
            'OEBPS/content.opf' => $opfXxe,
            'OEBPS/ch1.xhtml' => $this->xhtml('x', '<p>hola</p>'),
        ]);

        $this->expectException(ValidationException::class);
        $this->parse($path);
    }

    public function test_epub_malformado_lanza_error(): void
    {
        $path = $this->buildEpub([
            'META-INF/container.xml' => $this->container(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->parse($path);
    }
}
