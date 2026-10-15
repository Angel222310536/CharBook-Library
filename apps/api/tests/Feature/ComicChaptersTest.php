<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ComicPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComicChaptersTest extends TestCase
{
    use RefreshDatabase;

    private function png(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    /** @param array<string,string> $entries */
    private function zipUpload(array $entries, string $name = 'serie.cbz'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'serie').'.zip';
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();
        $bin = file_get_contents($tmp);
        @unlink($tmp);

        return UploadedFile::fake()->createWithContent($name, $bin);
    }

    private function serieBook(User $creator): Book
    {
        return Book::create([
            'creator_id' => $creator->id,
            'title' => 'Serie de cómic',
            'slug' => 'serie-comic-'.uniqid(),
            'description' => 'tmp',
            'work_format' => 'serie',
            'is_published' => true,
            'submission_status' => 'approved',
            'language' => 'es',
        ]);
    }

    public function test_import_comic_series_auto_detects_chapters_by_folder(): void
    {
        $creator = User::factory()->create();
        $book = $this->serieBook($creator);
        Sanctum::actingAs($creator);

        $png = $this->png();
        $upload = $this->zipUpload([
            'Capitulo 02/p-01.png' => $png,
            'Capitulo 01/p-02.png' => $png,
            'Capitulo 01/p-01.png' => $png,
            'Capitulo 02/p-02.png' => $png,
        ]);

        $res = $this->post("/api/me/works/{$book->id}/chapters/import-comic", ['file' => $upload], ['Accept' => 'application/json']);

        $res->assertCreated();
        $this->assertCount(2, $res->json('data'));

        // Capítulos creados en orden natural de carpeta.
        $chapters = $book->chapters()->orderBy('number')->get();
        $this->assertSame(['Capitulo 01', 'Capitulo 02'], $chapters->pluck('title')->all());

        // Cada página queda asociada a su capítulo.
        $this->assertSame(2, ComicPage::where('chapter_id', $chapters[0]->id)->count());
        $this->assertSame(2, ComicPage::where('chapter_id', $chapters[1]->id)->count());
    }

    public function test_chapter_comic_pages_appear_in_public_read_as_webtoon(): void
    {
        $creator = User::factory()->create();
        $book = $this->serieBook($creator);
        Sanctum::actingAs($creator);

        $this->post("/api/me/works/{$book->id}/chapters/import-comic", [
            'file' => $this->zipUpload(['Cap 01/a.png' => $this->png(), 'Cap 01/b.png' => $this->png()]),
        ], ['Accept' => 'application/json'])->assertCreated();

        // Lectura pública del capítulo 1 → debe traer las páginas (webtoon).
        $read = $this->getJson("/api/books/{$book->slug}/chapters/1");
        $read->assertOk();
        $this->assertCount(2, $read->json('data.pages'));
        $this->assertNotNull($read->json('data.pages.0.image_url'));
    }

    public function test_cannot_import_comic_chapters_into_others_work(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $book = $this->serieBook($owner);
        Sanctum::actingAs($intruder);

        $this->post("/api/me/works/{$book->id}/chapters/import-comic", [
            'file' => $this->zipUpload(['c/a.png' => $this->png()]),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }
}
