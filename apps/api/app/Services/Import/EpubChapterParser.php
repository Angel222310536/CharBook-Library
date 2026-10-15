<?php

namespace App\Services\Import;

use App\Services\FileGuard;
use App\Services\Import\Concerns\DetectsChapters;

/**
 * Parser de EPUB → capítulos en TEXTO PLANO (Fase A: sin HTML, sin imágenes,
 * sin cambiar el lector).
 *
 * Lee el contenedor en orden de lectura real:
 *   META-INF/container.xml → OPF (manifest + spine) → orden del spine.
 * Los títulos salen del TOC (toc.ncx EPUB2 o nav.xhtml EPUB3); si no hay TOC,
 * se infieren del propio XHTML (<title>/<h1>) y se avisa en el preview.
 *
 * Seguridad:
 *  - El XML estructural (container/OPF/NCX) se parsea con FileGuard::loadXmlSafe
 *    (rechaza <!ENTITY> → anti-XXE, sin acceso a red).
 *  - El XHTML de contenido NO se parsea como XML ni se renderiza: se quitan
 *    <script>/<style>, se eliminan etiquetas y se decodifican entidades HTML
 *    estándar. Una entidad personalizada (&xxe;) NUNCA se expande → no hay XXE.
 *  - El anti zip-bomb (assertSafeArchive) ya lo aplicó validateImport.
 */
class EpubChapterParser implements ChapterParser
{
    use DetectsChapters;

    private const XHTML_TYPES = ['application/xhtml+xml', 'text/html', 'application/html+xml'];

    /** Mínimo de caracteres reales para considerar una página un capítulo. */
    private const MIN_CHARS = 100;

    private FileGuard $guard;

    public function __construct(?FileGuard $guard = null)
    {
        $this->guard = $guard ?? new FileGuard;
    }

    public function supports(string $ext): bool
    {
        return $ext === 'epub';
    }

    /** @return ParsedChapter[] */
    public function parse(string $absolutePath): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            throw new \RuntimeException('No se pudo abrir el EPUB (ZIP inválido).');
        }

        try {
            $opfPath = $this->findOpfPath($zip);
            $opfXml = $this->read($zip, $opfPath);
            if ($opfXml === null) {
                throw new \RuntimeException('EPUB mal formado: falta el paquete OPF.');
            }

            $opf = $this->guard->loadXmlSafe($opfXml, 'file'); // anti-XXE
            $baseDir = $this->dirOf($opfPath);

            [$manifest, $spine, $tocId, $navHref, $technical] = $this->parseOpf($opf, $baseDir);
            if (empty($spine)) {
                throw new \RuntimeException('EPUB mal formado: el spine está vacío.');
            }

            $tocMap = $this->buildTocMap($zip, $manifest, $tocId, $navHref);
            $hasToc = ! empty($tocMap);

            // 1) Candidatos en orden de lectura real (spine), descartando nav/portada-imagen.
            $candidates = [];
            foreach ($spine as $idref) {
                $item = $manifest[$idref] ?? null;
                if (! $item || ! $this->isXhtml($item)) {
                    continue;
                }
                $props = (string) $item['props'];
                if (str_contains($props, 'nav') || str_contains($props, 'cover-image')) {
                    continue;
                }
                $xhtml = $this->read($zip, $item['href']);
                if ($xhtml === null) {
                    continue;
                }
                $candidates[] = [
                    'title' => $tocMap[$item['href']] ?? $this->titleFromXhtml($xhtml) ?? '',
                    'text' => $this->xhtmlToText($xhtml),
                    'hasImages' => preg_match('/<(img|image|svg)\b/i', $xhtml) === 1,
                    'technical' => isset($technical[$item['href']]),
                ];
            }

            // 2) Filtrar páginas técnicas / vacías / demasiado cortas.
            $kept = array_values(array_filter($candidates, fn ($c) => ! $this->shouldSkip($c)));

            // 3) Fallback: si TODO se filtró pero hay contenido legible, no perder el libro.
            if (empty($kept)) {
                $kept = array_values(array_filter(
                    $candidates,
                    fn ($c) => ! $c['technical'] && mb_strlen(trim($c['text'])) >= self::MIN_CHARS,
                ));
            }
            if (empty($kept)) {
                return [];
            }

            $omitted = count($candidates) - count($kept);

            // 4) Construir capítulos (warnings de TOC/omitidos/imágenes en el 1.º).
            $chapters = [];
            foreach ($kept as $i => $c) {
                $title = $c['title'] !== '' ? $c['title'] : 'Capítulo '.($i + 1);
                $warnings = [];
                if ($i === 0 && ! $hasToc) {
                    $warnings[] = 'El EPUB no tiene índice (TOC) claro; los títulos se infirieron del contenido.';
                }
                if ($i === 0 && $omitted > 0) {
                    $warnings[] = "Se omitieron {$omitted} página(s) técnica(s) (portada, índice, créditos…).";
                }
                if ($c['hasImages']) {
                    $warnings[] = 'Se omitieron imágenes.';
                }
                $chapters[] = $this->makeChapter($title, $c['text'], $warnings ? implode(' ', $warnings) : null);
            }

            return $chapters;
        } finally {
            $zip->close();
        }
    }

    /**
     * Extrae las IMÁGENES de un EPUB de cómic (solo-imágenes / webtoon) en orden
     * de lectura real (spine → <img>/<image> de cada XHTML). Devuelve los bytes
     * para guardarlos como páginas de cómic. No renderiza nada (no necesita GD/
     * Imagick): solo lee los archivos de imagen ya presentes en el ZIP.
     *
     * @return array<int,array{ext:string,bytes:string}>
     */
    public function extractComicImages(string $absolutePath): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            throw new \RuntimeException('No se pudo abrir el EPUB (ZIP inválido).');
        }

        try {
            $opfPath = $this->findOpfPath($zip);
            $opfXml = $this->read($zip, $opfPath);
            if ($opfXml === null) {
                throw new \RuntimeException('EPUB mal formado: falta el paquete OPF.');
            }
            $opf = $this->guard->loadXmlSafe($opfXml, 'file');
            $baseDir = $this->dirOf($opfPath);
            [$manifest, $spine] = $this->parseOpf($opf, $baseDir);

            // href (normalizado) → extensión, solo de items de imagen del manifest.
            $imageItems = [];
            foreach ($manifest as $item) {
                $ext = $this->imageExt($item['type'], $item['href']);
                if ($ext !== null) {
                    $imageItems[$item['href']] = $ext;
                }
            }

            // Orden de lectura: imágenes referenciadas por los XHTML del spine.
            $ordered = [];
            $seen = [];
            foreach ($spine as $idref) {
                $item = $manifest[$idref] ?? null;
                if (! $item) {
                    continue;
                }
                // El item del spine ya es una imagen (poco común).
                if (isset($imageItems[$item['href']]) && ! isset($seen[$item['href']])) {
                    $ordered[] = $item['href'];
                    $seen[$item['href']] = true;

                    continue;
                }
                if (! $this->isXhtml($item)) {
                    continue;
                }
                $xhtml = $this->read($zip, $item['href']);
                if ($xhtml === null) {
                    continue;
                }
                $pageDir = $this->dirOf($item['href']);
                if (preg_match_all('/<(?:img|image)\b[^>]*?(?:xlink:href|href|src)\s*=\s*["\']([^"\']+)["\']/i', $xhtml, $m)) {
                    foreach ($m[1] as $src) {
                        $resolved = $this->resolve($pageDir, $src);
                        if (isset($imageItems[$resolved]) && ! isset($seen[$resolved])) {
                            $ordered[] = $resolved;
                            $seen[$resolved] = true;
                        }
                    }
                }
            }

            // Fallback: si el spine no referenció imágenes, usa todas las del manifest.
            if (empty($ordered)) {
                $ordered = array_keys($imageItems);
            }

            $out = [];
            foreach ($ordered as $href) {
                $bytes = $this->read($zip, $href);
                if ($bytes === null || $bytes === '') {
                    continue;
                }
                // Confirma que es una imagen real (sin GD): getimagesizefromstring.
                $info = @getimagesizefromstring($bytes);
                if ($info === false) {
                    continue;
                }
                $out[] = ['ext' => $imageItems[$href], 'bytes' => $bytes];
            }

            return $out;
        } finally {
            $zip->close();
        }
    }

    /** Extensión canónica de imagen soportada en cómics, o null si no aplica. */
    private function imageExt(string $mediaType, string $href): ?string
    {
        return match (true) {
            $mediaType === 'image/jpeg' => 'jpg',
            $mediaType === 'image/png' => 'png',
            $mediaType === 'image/webp' => 'webp',
            $mediaType === 'image/gif' => 'gif',
            $mediaType === '' && preg_match('/\.(jpe?g)$/i', $href) === 1 => 'jpg',
            $mediaType === '' && preg_match('/\.png$/i', $href) === 1 => 'png',
            $mediaType === '' && preg_match('/\.webp$/i', $href) === 1 => 'webp',
            $mediaType === '' && preg_match('/\.gif$/i', $href) === 1 => 'gif',
            default => null,
        };
    }

    /**
     * ¿Esta página del spine NO debe importarse como capítulo?
     * Descarta páginas técnicas (guide), títulos típicos de portada/índice/
     * créditos, páginas vacías o solo-imagen, y páginas demasiado cortas (salvo
     * que el título parezca un capítulo real).
     *
     * @param  array{title:string,text:string,hasImages:bool,technical:bool}  $c
     */
    private function shouldSkip(array $c): bool
    {
        if ($c['technical'] || $this->isJunkTitle($c['title'])) {
            return true;
        }
        $len = mb_strlen(trim($c['text']));
        if ($len === 0) {
            return true; // vacía o solo imágenes
        }

        return $len < self::MIN_CHARS && ! $this->looksLikeHeading($c['title']);
    }

    /** Títulos de páginas técnicas que no son capítulos de lectura. */
    private function isJunkTitle(string $title): bool
    {
        $t = trim(mb_strtolower($title));
        if ($t === '') {
            return false;
        }

        return (bool) preg_match(
            '/^(cover|portada|t[íi]tulo|title\s*page|p[áa]gina de t[íi]tulo|half[\s-]?title|'
            .'copyright|uncopyright|derechos|cr[ée]ditos|colof[óo]n|colophon|imprint|'
            .'table of contents|contents|contenido|[íi]ndice|toc|nav|navigation|'
            .'acknowledg|agradecimientos|dedicat|about the (author|publisher)|sobre el autor)/u',
            $t,
        );
    }

    /** Lee container.xml y devuelve la ruta normalizada del OPF. */
    private function findOpfPath(\ZipArchive $zip): string
    {
        $containerXml = $this->read($zip, 'META-INF/container.xml');
        if ($containerXml === null) {
            throw new \RuntimeException('EPUB mal formado: falta META-INF/container.xml.');
        }

        $dom = $this->guard->loadXmlSafe($containerXml, 'file'); // anti-XXE
        $rootfiles = $dom->getElementsByTagNameNS('*', 'rootfile');
        foreach ($rootfiles as $rootfile) {
            /** @var \DOMElement $rootfile */
            $fullPath = $rootfile->getAttribute('full-path');
            if ($fullPath !== '') {
                return $this->normalizeSegments($fullPath);
            }
        }

        throw new \RuntimeException('EPUB mal formado: container.xml sin rootfile.');
    }

    /**
     * @return array{0:array<string,array{href:string,type:string,props:string}>,1:string[],2:?string,3:?string,4:array<string,bool>}
     *                                                                                                                                [manifest(id→item), spine(idrefs en orden), tocNcxId, navHref, technicalHrefs]
     */
    private function parseOpf(\DOMDocument $opf, string $baseDir): array
    {
        $manifest = [];
        foreach ($opf->getElementsByTagNameNS('*', 'item') as $item) {
            /** @var \DOMElement $item */
            $id = $item->getAttribute('id');
            $href = $item->getAttribute('href');
            if ($id === '' || $href === '') {
                continue;
            }
            $manifest[$id] = [
                'href' => $this->resolve($baseDir, $href),
                'type' => strtolower($item->getAttribute('media-type')),
                'props' => $item->getAttribute('properties'),
            ];
        }

        $spine = [];
        $tocId = null;
        $spineNodes = $opf->getElementsByTagNameNS('*', 'spine');
        if ($spineNodes->length > 0) {
            /** @var \DOMElement $spineEl */
            $spineEl = $spineNodes->item(0);
            $tocId = $spineEl->getAttribute('toc') ?: null;
            foreach ($spineEl->getElementsByTagNameNS('*', 'itemref') as $ref) {
                /** @var \DOMElement $ref */
                $idref = $ref->getAttribute('idref');
                if ($idref !== '') {
                    $spine[] = $idref;
                }
            }
        }

        // EPUB3: documento de navegación marcado con properties="nav".
        $navHref = null;
        foreach ($manifest as $item) {
            if (str_contains((string) $item['props'], 'nav')) {
                $navHref = $item['href'];
                break;
            }
        }

        // EPUB2 <guide>: marca páginas técnicas por href (portada, índice, etc.).
        $technical = [];
        $techTypes = ['cover', 'toc', 'title-page', 'copyright-page', 'acknowledgements', 'dedication', 'colophon', 'index', 'glossary', 'notes'];
        foreach ($opf->getElementsByTagNameNS('*', 'reference') as $ref) {
            /** @var \DOMElement $ref */
            $type = strtolower($ref->getAttribute('type'));
            $href = $ref->getAttribute('href');
            if ($href !== '' && in_array($type, $techTypes, true)) {
                $technical[$this->resolve($baseDir, $href)] = true;
            }
        }

        return [$manifest, $spine, $tocId, $navHref, $technical];
    }

    /**
     * Construye el mapa hrefNormalizado → título desde el NCX (EPUB2) o el
     * nav.xhtml (EPUB3). Si algo falla, devuelve vacío (→ aviso "sin TOC").
     *
     * @param  array<string,array{href:string,type:string,props:string}>  $manifest
     * @return array<string,string>
     */
    private function buildTocMap(\ZipArchive $zip, array $manifest, ?string $tocId, ?string $navHref): array
    {
        try {
            // EPUB2: toc.ncx
            if ($tocId !== null && isset($manifest[$tocId])) {
                $ncxPath = $manifest[$tocId]['href'];
                $ncxXml = $this->read($zip, $ncxPath);
                if ($ncxXml !== null) {
                    return $this->parseNcx($ncxXml, $this->dirOf($ncxPath));
                }
            }

            // EPUB3: nav.xhtml
            if ($navHref !== null) {
                $navXml = $this->read($zip, $navHref);
                if ($navXml !== null) {
                    return $this->parseNav($navXml, $this->dirOf($navHref));
                }
            }
        } catch (\Throwable $e) {
            // TOC mal formado → tratamos como "sin TOC" (no rompe la importación).
        }

        return [];
    }

    /**
     * @return array<string,string>
     */
    private function parseNcx(string $ncxXml, string $ncxDir): array
    {
        $dom = $this->guard->loadXmlSafe($ncxXml, 'file'); // anti-XXE
        $map = [];
        foreach ($dom->getElementsByTagNameNS('*', 'navPoint') as $navPoint) {
            /** @var \DOMElement $navPoint */
            $textNodes = $navPoint->getElementsByTagNameNS('*', 'text');
            $contentNodes = $navPoint->getElementsByTagNameNS('*', 'content');
            if ($textNodes->length === 0 || $contentNodes->length === 0) {
                continue;
            }
            $title = trim($textNodes->item(0)->textContent ?? '');
            /** @var \DOMElement $content */
            $content = $contentNodes->item(0);
            $src = $content->getAttribute('src');
            if ($title !== '' && $src !== '') {
                $map[$this->resolve($ncxDir, $src)] = $title;
            }
        }

        return $map;
    }

    /**
     * Lee el nav.xhtml con regex (no como XML estricto: el XHTML real suele
     * traer entidades HTML que romperían un parser XML). Solo extrae enlaces.
     *
     * @return array<string,string>
     */
    private function parseNav(string $navXml, string $navDir): array
    {
        // Acota al <nav ... toc ...> si existe; si no, usa todo el documento.
        if (preg_match('#<nav\b[^>]*epub:type=["\']toc["\'][^>]*>(.*?)</nav>#is', $navXml, $m)) {
            $scope = $m[1];
        } else {
            $scope = $navXml;
        }

        $map = [];
        if (preg_match_all('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $scope, $links, PREG_SET_ORDER)) {
            foreach ($links as $link) {
                $title = $this->plainInline($link[2]);
                if ($title !== '') {
                    $map[$this->resolve($navDir, $link[1])] = $title;
                }
            }
        }

        return $map;
    }

    /** Convierte un fragmento XHTML en texto plano seguro (sin render ni scripts). */
    private function xhtmlToText(string $html): string
    {
        // Quita el <head> (título, meta, css…) para no mezclarlo con el cuerpo.
        $html = preg_replace('#<head\b[^>]*>.*?</head>#is', ' ', $html) ?? $html;
        // Elimina por completo bloques ejecutables/estilos.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        // Saltos de línea en <br> y al cerrar bloques.
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|h[1-6]|li|tr|section|article|blockquote|pre|figcaption)\s*>#i', "\n\n", $html) ?? $html;
        // Quita TODAS las etiquetas restantes (incluye <!DOCTYPE>, <!ENTITY>, comentarios).
        $text = strip_tags($html);
        // Decodifica solo entidades HTML estándar (&amp; &nbsp; &#233;…). Una
        // entidad personalizada (&xxe;) queda literal → no se expande.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->cleanBody($text);
    }

    /** Texto plano de un fragmento inline (títulos de enlaces del nav). */
    private function plainInline(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function titleFromXhtml(string $html): ?string
    {
        foreach (['#<title[^>]*>(.*?)</title>#is', '#<h[1-3][^>]*>(.*?)</h[1-3]>#is'] as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                $title = $this->plainInline($m[1]);
                if ($title !== '') {
                    return $title;
                }
            }
        }

        return null;
    }

    /** @param array{href:string,type:string,props:string} $item */
    private function isXhtml(array $item): bool
    {
        if (in_array($item['type'], self::XHTML_TYPES, true)) {
            return true;
        }
        if ($item['type'] === '') {
            return (bool) preg_match('/\.(x?html?|xml)$/i', $item['href']);
        }

        return false;
    }

    private function read(\ZipArchive $zip, string $path): ?string
    {
        $content = $zip->getFromName($path);

        return $content === false ? null : $content;
    }

    /** Resuelve un href (relativo al base) a una ruta de entrada del ZIP. */
    private function resolve(string $base, string $href): string
    {
        $href = rawurldecode($href);
        $href = preg_replace('/#.*$/', '', $href) ?? $href; // quita fragmento
        $path = $base === '' ? $href : $base.'/'.$href;

        return $this->normalizeSegments($path);
    }

    private function normalizeSegments(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $parts = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $seg;
        }

        return implode('/', $parts);
    }

    private function dirOf(string $path): string
    {
        $pos = strrpos($path, '/');

        return $pos === false ? '' : substr($path, 0, $pos);
    }
}
