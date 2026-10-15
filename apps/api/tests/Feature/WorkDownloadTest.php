<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('scout.driver', null);
    }

    /** @param array<int,int> $chapterPrices */
    private function makeBook(bool $allow, array $chapterPrices = [0, 0]): Book
    {
        $author = Author::create(['name' => 'QA Download']);
        $book = Book::create([
            'title' => 'QA Download Book',
            'description' => 'Fixture',
            'author_id' => $author->id,
            'access_type' => 'free',
            'allow_download' => $allow,
            'language' => 'es',
            'is_published' => true,
            'work_format' => 'serie',
            'monetization' => ['free_chapters' => 0],
        ]);
        foreach ($chapterPrices as $i => $price) {
            Chapter::create([
                'book_id' => $book->id,
                'number' => $i + 1,
                'title' => 'Capítulo '.($i + 1),
                'body' => "Contenido del capítulo ".($i + 1).".\n\nSegundo párrafo con acentos: áéí.",
                'position' => $i + 1,
                'is_published' => true,
                'coin_price' => $price,
            ]);
        }

        return $book->fresh();
    }

    public function test_download_is_blocked_when_creator_disabled_it(): void
    {
        $book = $this->makeBook(false);

        $this->get("/api/books/{$book->slug}/download")->assertForbidden();
    }

    public function test_download_returns_a_valid_epub_for_a_free_series(): void
    {
        $book = $this->makeBook(true, [0, 0, 0]);

        $res = $this->get("/api/books/{$book->slug}/download");

        $res->assertOk();
        $this->assertStringContainsString('epub+zip', (string) $res->headers->get('content-type'));
        $bytes = $res->getContent();
        $this->assertStringStartsWith('PK', $bytes);                 // es un ZIP
        $this->assertStringContainsString('application/epub+zip', $bytes); // entrada mimetype
        $this->assertSame(1, (int) $book->fresh()->downloads_count);
    }

    public function test_download_excludes_locked_paid_chapters(): void
    {
        // 1 gratis + 1 de pago (no desbloqueado): el EPUB se genera con lo accesible.
        $book = $this->makeBook(true, [0, 10]);
        Sanctum::actingAs(User::factory()->create()); // lector sin plan

        $res = $this->get("/api/books/{$book->slug}/download");

        $res->assertOk();
        $this->assertStringStartsWith('PK', $res->getContent());
    }

    public function test_work_create_requires_the_download_choice(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $res = $this->postJson('/api/me/works', [
            'title' => 'Obra de prueba',
            'description' => str_repeat('x', 40),
            'work_format' => 'serie',
            'language' => 'es',
            'age_rating' => 'all',
            'copyright_holder' => 'Yo',
            'is_original' => true,
            // allow_download omitido a propósito
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['allow_download']);
    }
}
