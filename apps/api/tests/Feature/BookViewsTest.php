<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookView;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_reader_counts_once_per_book(): void
    {
        $book = $this->book();
        $reader = User::factory()->create();

        Sanctum::actingAs($reader);

        $this->postJson("/api/books/{$book->slug}/view")
            ->assertOk()
            ->assertJsonPath('data.unique_counted', true)
            ->assertJsonPath('data.session_counted', true)
            ->assertJsonPath('data.unique_readers_count', 1)
            ->assertJsonPath('data.total_readings_count', 1);

        $this->postJson("/api/books/{$book->slug}/view")
            ->assertOk()
            ->assertJsonPath('data.unique_counted', false)
            ->assertJsonPath('data.session_counted', false)
            ->assertJsonPath('data.unique_readers_count', 1)
            ->assertJsonPath('data.total_readings_count', 1);

        $book->refresh();
        $this->assertSame(1, $book->views_count);
        $this->assertSame(1, $book->total_readings_count);
        $this->assertSame(1, BookView::where('book_id', $book->id)->count());
    }

    public function test_new_session_increases_private_total_without_increasing_unique_readers(): void
    {
        $book = $this->book();
        $reader = User::factory()->create();
        Sanctum::actingAs($reader);

        Carbon::setTestNow(Carbon::parse('2026-06-22 10:00:00'));
        $this->postJson("/api/books/{$book->slug}/view")->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-06-22 10:31:00'));
        $this->postJson("/api/books/{$book->slug}/view")
            ->assertOk()
            ->assertJsonPath('data.unique_counted', false)
            ->assertJsonPath('data.session_counted', true)
            ->assertJsonPath('data.unique_readers_count', 1)
            ->assertJsonPath('data.total_readings_count', 2);

        Carbon::setTestNow();

        $book->refresh();
        $this->assertSame(1, $book->views_count);
        $this->assertSame(2, $book->total_readings_count);
        $this->assertSame(2, BookView::where('book_id', $book->id)->firstOrFail()->sessions_count);
    }

    public function test_guest_view_is_deduplicated_by_visitor_key(): void
    {
        $book = $this->book();

        $this->postJson("/api/books/{$book->slug}/view", ['visitor_key' => 'browser-one'])
            ->assertOk()
            ->assertJsonPath('data.unique_counted', true)
            ->assertJsonPath('data.unique_readers_count', 1);

        $this->postJson("/api/books/{$book->slug}/view", ['visitor_key' => 'browser-one'])
            ->assertOk()
            ->assertJsonPath('data.unique_counted', false)
            ->assertJsonPath('data.unique_readers_count', 1);

        $this->postJson("/api/books/{$book->slug}/view", ['visitor_key' => 'browser-two'])
            ->assertOk()
            ->assertJsonPath('data.unique_counted', true)
            ->assertJsonPath('data.unique_readers_count', 2);

        $book->refresh();
        $this->assertSame(2, $book->views_count);
        $this->assertSame(2, $book->total_readings_count);
    }

    public function test_fetching_book_detail_no_longer_increments_views(): void
    {
        $book = $this->book([
            'views_count' => 7,
            'total_readings_count' => 19,
        ]);

        $this->getJson("/api/books/{$book->slug}")->assertOk();
        $this->getJson("/api/books/{$book->slug}")->assertOk();

        $book->refresh();
        $this->assertSame(7, $book->views_count);
        $this->assertSame(19, $book->total_readings_count);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function book(array $overrides = []): Book
    {
        $author = Author::create([
            'name' => 'QA Views Author ' . str()->random(8),
        ]);

        return Book::create(array_merge([
            'author_id' => $author->id,
            'title' => 'QA Views Book ' . str()->random(8),
            'slug' => 'qa-views-book-' . str()->random(8),
            'description' => 'Book views test.',
            'access_type' => 'free',
            'is_published' => true,
            'is_hidden' => false,
            'file_type' => 'epub',
            'views_count' => 0,
            'total_readings_count' => 0,
        ], $overrides));
    }
}
