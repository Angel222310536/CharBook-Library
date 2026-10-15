<?php

namespace App\Services\Export;

use App\Models\Book;
use Illuminate\Support\Collection;
use ZipArchive;

/**
 * Genera un EPUB válido (un solo archivo) a partir de los capítulos de TEXTO de
 * una obra. Sin dependencias externas: usa ZipArchive (incluido en PHP).
 *
 * Los capítulos se pasan ya filtrados por acceso y en orden de lectura.
 */
class BookEpubExporter
{
    /**
     * @param  Collection<int, \App\Models\Chapter>  $chapters  con `body` resuelto (texto plano)
     * @return string  binario del archivo .epub
     */
    public function build(Book $book, Collection $chapters): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cbk_epub_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        // El "mimetype" debe ir primero y SIN comprimir (requisito del formato).
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

        $zip->addFromString('META-INF/container.xml', $this->containerXml());
        $zip->addFromString('OEBPS/style.css', $this->css());

        $uid = 'urn:charbook:book:'.$book->id;
        $title = $this->esc($book->title ?: 'Obra');
        $author = $this->esc($book->creator->name ?? $book->author->name ?? 'CharBook Library');
        $lang = preg_match('/^[a-z]{2}$/i', (string) $book->language) ? strtolower($book->language) : 'es';

        $zip->addFromString('OEBPS/title.xhtml', $this->titlePage($title, $author));

        $manifest = [
            '<item id="titlepage" href="title.xhtml" media-type="application/xhtml+xml"/>',
            '<item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>',
            '<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>',
            '<item id="css" href="style.css" media-type="text/css"/>',
        ];
        $spine = ['<itemref idref="titlepage"/>'];
        $navLis = [];
        $ncxPoints = [];

        foreach ($chapters->values() as $i => $c) {
            $id = 'chap'.($i + 1);
            $href = $id.'.xhtml';
            $ctitle = $this->esc(($c->title !== null && trim($c->title) !== '') ? $c->title : ('Capítulo '.$c->number));

            $zip->addFromString("OEBPS/{$href}", $this->chapterXhtml($ctitle, (string) $c->body));

            $manifest[] = "<item id=\"{$id}\" href=\"{$href}\" media-type=\"application/xhtml+xml\"/>";
            $spine[] = "<itemref idref=\"{$id}\"/>";
            $navLis[] = "        <li><a href=\"{$href}\">{$ctitle}</a></li>";
            $order = $i + 2;
            $ncxPoints[] = "    <navPoint id=\"np-{$id}\" playOrder=\"{$order}\"><navLabel><text>{$ctitle}</text></navLabel><content src=\"{$href}\"/></navPoint>";
        }

        $zip->addFromString('OEBPS/nav.xhtml', $this->navXhtml($title, $navLis));
        $zip->addFromString('OEBPS/toc.ncx', $this->ncxXml($uid, $title, $ncxPoints));
        $zip->addFromString('OEBPS/content.opf', $this->opfXml($uid, $title, $author, $lang, $manifest, $spine));

        $zip->close();
        $binary = file_get_contents($tmp);
        @unlink($tmp);

        return $binary;
    }

    private function containerXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>
XML;
    }

    private function opfXml(string $uid, string $title, string $author, string $lang, array $manifest, array $spine): string
    {
        $modified = gmdate('Y-m-d\TH:i:s\Z');
        $manifestXml = implode("\n    ", $manifest);
        $spineXml = implode("\n    ", $spine);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="bookid">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="bookid">{$uid}</dc:identifier>
    <dc:title>{$title}</dc:title>
    <dc:creator>{$author}</dc:creator>
    <dc:language>{$lang}</dc:language>
    <meta property="dcterms:modified">{$modified}</meta>
  </metadata>
  <manifest>
    {$manifestXml}
  </manifest>
  <spine toc="ncx">
    {$spineXml}
  </spine>
</package>
XML;
    }

    private function ncxXml(string $uid, string $title, array $points): string
    {
        $pointsXml = implode("\n", $points);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1">
  <head>
    <meta name="dtb:uid" content="{$uid}"/>
  </head>
  <docTitle><text>{$title}</text></docTitle>
  <navMap>
    <navPoint id="np-title" playOrder="1"><navLabel><text>{$title}</text></navLabel><content src="title.xhtml"/></navPoint>
{$pointsXml}
  </navMap>
</ncx>
XML;
    }

    private function navXhtml(string $title, array $lis): string
    {
        $items = implode("\n", $lis);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" lang="es">
<head><meta charset="utf-8"/><title>{$title}</title></head>
<body>
  <nav epub:type="toc" id="toc">
    <h1>Índice</h1>
    <ol>
{$items}
    </ol>
  </nav>
</body>
</html>
XML;
    }

    private function titlePage(string $title, string $author): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="es">
<head><meta charset="utf-8"/><title>{$title}</title><link rel="stylesheet" type="text/css" href="style.css"/></head>
<body>
  <section class="titlepage">
    <h1 class="book-title">{$title}</h1>
    <p class="book-author">{$author}</p>
    <p class="book-source">Descargado de CharBook Library</p>
  </section>
</body>
</html>
XML;
    }

    private function chapterXhtml(string $title, string $body): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="es">
<head><meta charset="utf-8"/><title>{$title}</title><link rel="stylesheet" type="text/css" href="style.css"/></head>
<body>
  <h2 class="chapter-title">{$title}</h2>
  {$this->bodyToParagraphs($body)}
</body>
</html>
XML;
    }

    /** Texto plano → párrafos XHTML seguros. Doble salto = párrafo; salto simple = <br/>. */
    private function bodyToParagraphs(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = preg_split('/\n{2,}/', trim($text)) ?: [];

        $html = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $escaped = $this->esc($block);
            $escaped = nl2br($escaped, true);
            $html[] = '<p>'.$escaped.'</p>';
        }

        return $html ? implode("\n  ", $html) : '<p></p>';
    }

    private function css(): string
    {
        return <<<'CSS'
body { font-family: Georgia, "Times New Roman", serif; line-height: 1.6; margin: 5%; }
h1.book-title { font-size: 2em; text-align: center; margin-top: 30%; }
p.book-author { text-align: center; font-style: italic; color: #555; }
p.book-source { text-align: center; font-size: .8em; color: #999; margin-top: 2em; }
h2.chapter-title { font-size: 1.4em; margin: 1.5em 0 1em; text-align: center; }
p { margin: 0 0 1em; text-align: justify; text-indent: 1.2em; }
.titlepage { text-align: center; }
CSS;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
