<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\ComicPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Importación de páginas de cómic desde un EPUB de imágenes (extrae las
 * imágenes en orden). PDF se rechaza con mensaje claro (sin Imagick/Ghostscript).
 */
class ComicImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function comicBook(User $u): Book
    {
        $author = Author::create(['name' => 'QA']);

        return Book::create([
            'title' => 'Cómic QA', 'description' => 't', 'author_id' => $author->id,
            'creator_id' => $u->id, 'access_type' => 'free', 'language' => 'es',
            'is_published' => true, 'work_format' => 'comic',
        ]);
    }

    private function imageEpub(int $pages = 2): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMCAQDJjz3xAAAAAElFTkSuQmCC');

        $items = $spine = '';
        $files = [
            'META-INF/container.xml' => '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
        ];
        for ($i = 1; $i <= $pages; $i++) {
            $items .= "<item id=\"p{$i}\" href=\"page{$i}.xhtml\" media-type=\"application/xhtml+xml\"/><item id=\"i{$i}\" href=\"img{$i}.png\" media-type=\"image/png\"/>";
            $spine .= "<itemref idref=\"p{$i}\"/>";
            $files["OEBPS/page{$i}.xhtml"] = '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"><body><img src="img'.$i.'.png"/></body></html>';
            $files["OEBPS/img{$i}.png"] = $png;
        }
        $files['OEBPS/content.opf'] = '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="b"><metadata/><manifest>'.$items.'</manifest><spine>'.$spine.'</spine></package>';

        $path = tempnam(sys_get_temp_dir(), 'cepub').'.epub';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('mimetype', 'application/epub+zip');
        foreach ($files as $n => $c) {
            $zip->addFromString($n, $c);
        }
        $zip->close();

        return new UploadedFile($path, 'comic.epub', null, null, true);
    }

    public function test_importa_comic_desde_epub_crea_paginas_en_orden(): void
    {
        $u = User::factory()->create();
        $book = $this->comicBook($u);

        $res = $this->actingAs($u)
            ->postJson("/api/me/works/{$book->id}/pages/import", ['file' => $this->imageEpub(3)])
            ->assertCreated()
            ->json();

        $this->assertCount(3, $res['data']);
        $pages = ComicPage::where('book_id', $book->id)->orderBy('position')->get();
        $this->assertCount(3, $pages);
        $this->assertSame([1, 2, 3], $pages->pluck('position')->map(fn ($p) => (int) $p)->all());
        $this->assertNotNull($pages[0]->image_path);
    }

    public function test_importar_comic_pdf_devuelve_mensaje_claro(): void
    {
        $u = User::factory()->create();
        $book = $this->comicBook($u);

        $path = tempnam(sys_get_temp_dir(), 'p').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");

        $this->actingAs($u)
            ->postJson("/api/me/works/{$book->id}/pages/import", [
                'file' => new UploadedFile($path, 'comic.pdf', null, null, true),
            ])
            ->assertStatus(422);

        $this->assertSame(0, ComicPage::where('book_id', $book->id)->count());
        @unlink($path);
    }

    public function test_no_puede_importar_comic_en_obra_ajena(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $book = $this->comicBook($owner);

        $this->actingAs($intruder)
            ->postJson("/api/me/works/{$book->id}/pages/import", ['file' => $this->imageEpub(1)])
            ->assertStatus(403);
    }
}
