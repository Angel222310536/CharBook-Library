<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Importador de libros → capítulos (Fases 1-2: TXT y DOCX). La cola corre en
 * modo `sync` en testing, así que parse/creación ocurren en línea.
 */
class ChapterImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function fakeWith(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function makeBook(User $creator, array $overrides = []): Book
    {
        $author = Author::create(['name' => 'QA Author']);

        return Book::create(array_merge([
            'title' => 'Obra QA', 'description' => 'Fixture', 'author_id' => $author->id,
            'creator_id' => $creator->id, 'access_type' => 'free', 'language' => 'es',
            'is_published' => true, 'work_format' => 'serie',
        ], $overrides));
    }

    /** Genera un .docx real (con estilos de título como un Word real). */
    private function makeDocx(callable $build): UploadedFile
    {
        $word = new PhpWord;
        $word->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $word->addTitleStyle(2, ['bold' => true, 'size' => 14]);
        $section = $word->addSection();
        $build($section);
        $path = tempnam(sys_get_temp_dir(), 'docx').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return new UploadedFile($path, 'libro.docx', null, null, true);
    }

    /**
     * Genera un .epub real (mimetype + container + OPF + spine + NCX + XHTML).
     *
     * @param  array<int,array{file:string,title:string,body:string}>  $chapters
     * @param  array<string,string>  $override  Reemplaza entradas (XXE, etc.).
     */
    private function makeEpub(array $chapters, array $override = []): UploadedFile
    {
        $items = $spine = $nav = '';
        foreach ($chapters as $i => $c) {
            $items .= "<item id=\"c{$i}\" href=\"{$c['file']}\" media-type=\"application/xhtml+xml\"/>";
            $spine .= "<itemref idref=\"c{$i}\"/>";
            $nav .= "<navPoint id=\"n{$i}\" playOrder=\"{$i}\"><navLabel><text>{$c['title']}</text></navLabel><content src=\"{$c['file']}\"/></navPoint>";
        }
        $files = array_merge([
            'META-INF/container.xml' => '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
            'OEBPS/content.opf' => '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="b"><metadata/><manifest>'.$items.'<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/></manifest><spine toc="ncx">'.$spine.'</spine></package>',
            'OEBPS/toc.ncx' => '<?xml version="1.0"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><navMap>'.$nav.'</navMap></ncx>',
        ], $override);
        // Relleno para superar el mínimo de 100 caracteres reales (capítulos válidos).
        $pad = '<p>Relleno de contenido real con longitud mas que suficiente para superar el umbral minimo de cien caracteres reales del importador.</p>';
        foreach ($chapters as $c) {
            $files['OEBPS/'.$c['file']] = '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>'.$c['title'].'</title></head><body>'.$c['body'].$pad.'</body></html>';
        }

        $path = tempnam(sys_get_temp_dir(), 'epub').'.epub';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'libro.epub', null, null, true);
    }

    public function test_flujo_txt_completo_crea_capitulos_y_resumen(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        $txt = "Capítulo 1\nEl comienzo de la historia.\n\nCapítulo 2\nLa aventura continúa con fuerza.";

        $upload = $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", ['file' => $this->fakeWith('libro.txt', $txt)])
            ->assertCreated()
            ->json('data');

        // El parse (sync) ya dejó la vista previa lista.
        $this->assertSame(ChapterImport::STATUS_PREVIEW_READY, $upload['status']);
        $this->assertSame(2, $upload['total']);
        $this->assertCount(2, $upload['detected']);
        $this->assertSame('Capítulo 1', $upload['detected'][0]['title']);

        // Confirmar → crea capítulos reales.
        $done = $this->actingAs($creator)
            ->postJson("/api/me/imports/{$upload['id']}/confirm", ['publish' => true])
            ->assertOk()
            ->json('data');

        $this->assertSame(ChapterImport::STATUS_DONE, $done['status']);
        $this->assertSame(2, $done['created_count']);
        $this->assertGreaterThan(0, $done['word_count']);
        $this->assertSame(0, $done['error_count']);
        $this->assertNotNull($done['finished_at']);

        $chapters = Chapter::where('book_id', $book->id)->orderBy('number')->get();
        $this->assertCount(2, $chapters);
        $this->assertSame('Capítulo 1', $chapters[0]->title);
        $this->assertSame('El comienzo de la historia.', $chapters[0]->body);
        $this->assertTrue((bool) $chapters[0]->is_published);
        $this->assertSame([1, 2], $chapters->pluck('number')->map(fn ($n) => (int) $n)->all());
    }

    public function test_numeracion_continua_tras_capitulos_existentes(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);
        Chapter::create(['book_id' => $book->id, 'number' => 1, 'title' => 'Existente', 'position' => 1, 'is_published' => true]);

        $upload = $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", ['file' => $this->fakeWith('l.txt', "Capítulo 1\nA.\n\nCapítulo 2\nB.")])
            ->json('data');

        $this->actingAs($creator)->postJson("/api/me/imports/{$upload['id']}/confirm", [])->assertOk();

        $numbers = Chapter::where('book_id', $book->id)->orderBy('number')->pluck('number')->map(fn ($n) => (int) $n)->all();
        $this->assertSame([1, 2, 3], $numbers);
        // Los importados quedan como borrador por defecto (publish ausente).
        $this->assertSame(2, Chapter::where('book_id', $book->id)->where('is_published', false)->count());
    }

    public function test_rechaza_exe_renombrado_a_txt(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", ['file' => $this->fakeWith('libro.txt', "MZ\x90\x00\x03\x00\x00\x00")])
            ->assertStatus(422);

        $this->assertSame(0, ChapterImport::count());
    }

    public function test_epub_malformado_se_acepta_pero_falla_al_analizar(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        // EPUB válido como ZIP pero sin rootfile en container.xml → FileGuard lo
        // acepta (es un ZIP/epub legítimo) y el análisis lo marca como fallido.
        $zipPath = tempnam(sys_get_temp_dir(), 'epub').'.epub';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container/>');
        $zip->close();

        $upload = $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", [
                'file' => new UploadedFile($zipPath, 'libro.epub', null, null, true),
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(ChapterImport::STATUS_FAILED, $upload['status']);
        $this->assertSame(0, Chapter::where('book_id', $book->id)->count());
        @unlink($zipPath);
    }

    public function test_no_puede_importar_en_obra_ajena(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $book = $this->makeBook($owner);

        $this->actingAs($intruder)
            ->postJson("/api/me/works/{$book->id}/imports", ['file' => $this->fakeWith('l.txt', "Capítulo 1\nX.")])
            ->assertStatus(403);
    }

    public function test_no_puede_confirmar_importacion_ajena(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $book = $this->makeBook($owner);

        $upload = $this->actingAs($owner)
            ->postJson("/api/me/works/{$book->id}/imports", ['file' => $this->fakeWith('l.txt', "Capítulo 1\nX.")])
            ->json('data');

        $this->actingAs($intruder)
            ->postJson("/api/me/imports/{$upload['id']}/confirm", [])
            ->assertStatus(403);

        $this->assertSame(0, Chapter::where('book_id', $book->id)->count());
    }

    // ===================== DOCX (Fase 2) =====================

    public function test_flujo_docx_completo_crea_capitulos(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        $docx = $this->makeDocx(function ($s) {
            $s->addTitle('El Comienzo', 1);
            $s->addText('Contenido del primero.');
            $s->addTitle('La Travesía', 1);
            $s->addText('Contenido del segundo.');
        });

        $upload = $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", ['file' => $docx])
            ->assertCreated()
            ->json('data');

        $this->assertSame(ChapterImport::STATUS_PREVIEW_READY, $upload['status']);
        $this->assertSame(2, $upload['total']);
        $this->assertSame('El Comienzo', $upload['detected'][0]['title']);

        $done = $this->actingAs($creator)
            ->postJson("/api/me/imports/{$upload['id']}/confirm", ['publish' => true])
            ->assertOk()
            ->json('data');

        $this->assertSame(ChapterImport::STATUS_DONE, $done['status']);
        $this->assertSame(2, $done['created_count']);

        $chapters = Chapter::where('book_id', $book->id)->orderBy('number')->get();
        $this->assertCount(2, $chapters);
        $this->assertSame('El Comienzo', $chapters[0]->title);
        $this->assertStringContainsString('primero', $chapters[0]->body);
    }

    public function test_docx_renombrado_desde_exe_rechazado(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", [
                'file' => $this->fakeWith('libro.docx', "MZ\x90\x00\x03\x00\x00\x00"),
            ])
            ->assertStatus(422);

        $this->assertSame(0, ChapterImport::count());
    }

    public function test_docx_grande_dentro_del_limite_se_acepta(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        // 40 capítulos en un .docx válido (muy por debajo de 25 MB).
        $docx = $this->makeDocx(function ($s) {
            for ($i = 1; $i <= 40; $i++) {
                $s->addTitle("Capítulo {$i}", 1);
                $s->addText("Contenido del capítulo {$i} con algo de texto.");
            }
        });

        $upload = $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", ['file' => $docx])
            ->assertCreated()
            ->json('data');

        $this->assertSame(ChapterImport::STATUS_PREVIEW_READY, $upload['status']);
        $this->assertSame(40, $upload['total']);
    }

    public function test_docx_que_excede_limite_rechazado(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        // 26 MB con cabecera ZIP (PK) y extensión .docx → supera el límite de 25 MB.
        // El chequeo de tamaño por tipo salta antes que el de firma/zip.
        $payload = "PK\x03\x04".str_repeat('A', 26 * 1024 * 1024);

        $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", [
                'file' => $this->fakeWith('grande.docx', $payload),
            ])
            ->assertStatus(422);

        $this->assertSame(0, ChapterImport::count());
    }

    // ===================== EPUB (Fase 3) =====================

    public function test_flujo_epub_completo_crea_capitulos(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        $epub = $this->makeEpub([
            ['file' => 'ch1.xhtml', 'title' => 'El Comienzo', 'body' => '<p>Contenido uno.</p>'],
            ['file' => 'ch2.xhtml', 'title' => 'La Travesía', 'body' => '<p>Contenido dos.</p>'],
        ]);

        $upload = $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", ['file' => $epub])
            ->assertCreated()
            ->json('data');

        $this->assertSame(ChapterImport::STATUS_PREVIEW_READY, $upload['status']);
        $this->assertSame(2, $upload['total']);
        $this->assertSame('El Comienzo', $upload['detected'][0]['title']);

        $done = $this->actingAs($creator)
            ->postJson("/api/me/imports/{$upload['id']}/confirm", ['publish' => true])
            ->assertOk()
            ->json('data');

        $this->assertSame(ChapterImport::STATUS_DONE, $done['status']);
        $this->assertSame(2, $done['created_count']);

        $chapters = Chapter::where('book_id', $book->id)->orderBy('number')->get();
        $this->assertCount(2, $chapters);
        $this->assertSame('El Comienzo', $chapters[0]->title);
        $this->assertStringContainsString('Contenido uno.', $chapters[0]->body);
    }

    public function test_epub_numera_tras_capitulos_existentes(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);
        Chapter::create(['book_id' => $book->id, 'number' => 1, 'title' => 'Previo', 'position' => 1, 'is_published' => true]);

        $epub = $this->makeEpub([
            ['file' => 'ch1.xhtml', 'title' => 'Importado A', 'body' => '<p>a</p>'],
            ['file' => 'ch2.xhtml', 'title' => 'Importado B', 'body' => '<p>b</p>'],
        ]);

        $upload = $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", ['file' => $epub])
            ->json('data');
        $this->actingAs($creator)->postJson("/api/me/imports/{$upload['id']}/confirm", [])->assertOk();

        $numbers = Chapter::where('book_id', $book->id)->orderBy('number')->pluck('number')->map(fn ($n) => (int) $n)->all();
        $this->assertSame([1, 2, 3], $numbers);
    }

    public function test_importar_epub_con_cover_solo_crea_capitulo_real(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        // EPUB con portada (solo imagen), copyright y UN capítulo real.
        $opf = '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="b"><metadata/>'
            .'<manifest>'
            .'<item id="cover" href="cover.xhtml" media-type="application/xhtml+xml"/>'
            .'<item id="cp" href="copyright.xhtml" media-type="application/xhtml+xml"/>'
            .'<item id="ch1" href="ch1.xhtml" media-type="application/xhtml+xml"/>'
            .'<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/>'
            .'</manifest><spine toc="ncx"><itemref idref="cover"/><itemref idref="cp"/><itemref idref="ch1"/></spine>'
            .'<guide><reference type="cover" href="cover.xhtml" title="Cover"/></guide></package>';
        $ncx = '<?xml version="1.0"?><ncx xmlns="http://www.daisy.org/z3986/2005/ncx/" version="2005-1"><navMap>'
            .'<navPoint id="n0" playOrder="0"><navLabel><text>Cover</text></navLabel><content src="cover.xhtml"/></navPoint>'
            .'<navPoint id="n1" playOrder="1"><navLabel><text>Copyright</text></navLabel><content src="copyright.xhtml"/></navPoint>'
            .'<navPoint id="n2" playOrder="2"><navLabel><text>Capitulo Uno</text></navLabel><content src="ch1.xhtml"/></navPoint>'
            .'</navMap></ncx>';
        $cover = '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>Cover</title></head><body><img src="cover.jpg" alt="portada"/></body></html>';
        $cp = '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>Copyright</title></head><body><p>(c) 2020.</p></body></html>';
        $real = '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><head><title>Capitulo Uno</title></head><body><p>Texto real del capitulo con longitud mas que suficiente para superar el minimo de cien caracteres reales del importador.</p></body></html>';

        $path = tempnam(sys_get_temp_dir(), 'epub').'.epub';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        $zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
        $zip->addFromString('OEBPS/content.opf', $opf);
        $zip->addFromString('OEBPS/toc.ncx', $ncx);
        $zip->addFromString('OEBPS/cover.xhtml', $cover);
        $zip->addFromString('OEBPS/copyright.xhtml', $cp);
        $zip->addFromString('OEBPS/ch1.xhtml', $real);
        $zip->close();

        $upload = $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", ['file' => new UploadedFile($path, 'libro.epub', null, null, true)])
            ->assertCreated()
            ->json('data');
        @unlink($path);

        // Solo el capítulo real se detecta (portada + copyright omitidos).
        $this->assertSame(1, $upload['total']);
        $this->assertSame('Capitulo Uno', $upload['detected'][0]['title']);

        $this->actingAs($creator)->postJson("/api/me/imports/{$upload['id']}/confirm", ['publish' => true])->assertOk();

        $chapters = Chapter::where('book_id', $book->id)->get();
        $this->assertCount(1, $chapters);
        $this->assertNotSame('Cover', $chapters[0]->title);
        $this->assertNotEmpty(trim((string) $chapters[0]->body));

        // El lector devuelve body con texto real (no vacío, no descarga).
        $data = $this->getJson("/api/books/{$book->slug}/chapters/{$chapters[0]->number}")
            ->assertOk()
            ->json('data');
        $this->assertStringContainsString('Texto real', $data['body']);
    }

    public function test_epub_renombrado_desde_exe_rechazado(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", [
                'file' => $this->fakeWith('libro.epub', "MZ\x90\x00\x03\x00\x00\x00"),
            ])
            ->assertStatus(422);

        $this->assertSame(0, ChapterImport::count());
    }

    public function test_epub_que_excede_100mb_rechazado(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        // Archivo disperso de 101 MB (rápido, sin reservar 101 MB en RAM).
        $path = tempnam(sys_get_temp_dir(), 'big').'.epub';
        $fh = fopen($path, 'w');
        fwrite($fh, "PK\x03\x04");
        fseek($fh, 101 * 1024 * 1024 - 1);
        fwrite($fh, "\0");
        fclose($fh);

        $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", [
                'file' => new UploadedFile($path, 'enorme.epub', null, null, true),
            ])
            ->assertStatus(422);

        $this->assertSame(0, ChapterImport::count());
        @unlink($path);
    }

    public function test_epub_con_xxe_falla_sin_filtrar(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        $opfXxe = '<?xml version="1.0"?><!DOCTYPE package [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            .'<package xmlns="http://www.idpf.org/2007/opf" version="2.0" unique-identifier="b">'
            .'<metadata/><manifest><item id="c0" href="ch1.xhtml" media-type="application/xhtml+xml"/>'
            .'<item id="ncx" href="toc.ncx" media-type="application/x-dtbncx+xml"/></manifest>'
            .'<spine toc="ncx"><itemref idref="c0"/></spine></package>';

        $epub = $this->makeEpub(
            [['file' => 'ch1.xhtml', 'title' => 'x', 'body' => '<p>hola</p>']],
            ['OEBPS/content.opf' => $opfXxe],
        );

        // El upload pasa FileGuard (ZIP válido); el parse (sync) rechaza el XXE.
        $upload = $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", ['file' => $epub])
            ->assertCreated()
            ->json('data');

        $this->assertSame(ChapterImport::STATUS_FAILED, $upload['status']);
        $this->assertSame(0, Chapter::where('book_id', $book->id)->count());
    }

    public function test_endpoint_show_devuelve_estado_para_polling(): void
    {
        // El frontend hace polling de ESTE GET anidado (work → import). El
        // scoped binding requiere Book::imports(); sin él daba 500 y el modal
        // se quedaba en "Analizando".
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);
        $epub = $this->makeEpub([
            ['file' => 'ch1.xhtml', 'title' => 'Cap', 'body' => '<p>Hola.</p>'],
        ]);

        $upload = $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", ['file' => $epub])
            ->assertCreated()
            ->json('data');

        $status = $this->actingAs($creator)
            ->getJson("/api/me/works/{$book->id}/imports/{$upload['id']}")
            ->assertOk()
            ->json('data');

        $this->assertSame($upload['id'], $status['id']);
        $this->assertSame(ChapterImport::STATUS_PREVIEW_READY, $status['status']);
        $this->assertSame(1, $status['total']);
    }

    // ===== Lectura inline de archivos adjuntos (sin descarga) =====

    public function test_capitulo_con_epub_adjunto_se_lee_inline_sin_descarga(): void
    {
        Storage::fake('public');
        $creator = User::factory()->create();
        $book = $this->makeBook($creator); // publicado + gratis + serie

        // EPUB en disco público + capítulo que lo referencia, SIN body.
        $epub = $this->makeEpub([
            ['file' => 'ch1.xhtml', 'title' => 'Cap', 'body' => '<p>Texto que se lee inline.</p>'],
        ]);
        Storage::disk('public')->put('chapters/x.epub', file_get_contents($epub->getRealPath()));
        $chapter = Chapter::create([
            'book_id' => $book->id, 'number' => 1, 'title' => 'Capítulo 1', 'position' => 1,
            'is_published' => true, 'file_path' => 'chapters/x.epub', 'file_type' => 'epub',
        ]);

        // Endpoint público de lectura → devuelve el texto extraído (no "descargar").
        $data = $this->getJson("/api/books/{$book->slug}/chapters/1")
            ->assertOk()
            ->json('data');

        $this->assertStringContainsString('Texto que se lee inline.', $data['body']);
        // Y se cacheó en el capítulo para no re-parsear.
        $this->assertNotEmpty($chapter->fresh()->body);
    }

    public function test_epub_zip_bomb_por_entradas_rechazado(): void
    {
        $creator = User::factory()->create();
        $book = $this->makeBook($creator);

        // ZIP con > 5000 entradas (defensa anti zip-bomb por número de archivos).
        $path = tempnam(sys_get_temp_dir(), 'bomb').'.epub';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        for ($i = 0; $i < 5001; $i++) {
            $zip->addFromString("f{$i}.txt", 'x');
        }
        $zip->close();

        $this->actingAs($creator)
            ->postJson("/api/me/works/{$book->id}/imports", [
                'file' => new UploadedFile($path, 'bomba.epub', null, null, true),
            ])
            ->assertStatus(422);

        $this->assertSame(0, ChapterImport::count());
        @unlink($path);
    }
}
