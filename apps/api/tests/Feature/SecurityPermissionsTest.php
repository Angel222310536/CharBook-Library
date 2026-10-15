<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\CoinTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecurityPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['reader', 'creator', 'moderator', 'admin', 'super_admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_public_book_routes_do_not_expose_unpublished_or_hidden_books(): void
    {
        $draft = $this->book(['slug' => 'draft-work', 'is_published' => false]);
        $hidden = $this->book(['slug' => 'hidden-work', 'is_published' => true, 'is_hidden' => true]);

        Chapter::create([
            'book_id' => $hidden->id,
            'number' => 1,
            'position' => 1,
            'title' => 'Hidden chapter',
            'body' => 'Should not leak',
            'is_published' => true,
        ]);

        $this->getJson('/api/books/' . $draft->slug)->assertNotFound();
        $this->getJson('/api/books/' . $hidden->slug)->assertNotFound();
        $this->getJson('/api/books/' . $hidden->slug . '/chapters')->assertNotFound();
        $this->getJson('/api/books/' . $hidden->slug . '/chapters/1')->assertNotFound();
        $this->getJson('/api/books/' . $hidden->slug . '/pages')->assertNotFound();
    }

    public function test_public_file_proxy_rejects_monetized_books(): void
    {
        $book = $this->book([
            'slug' => 'paid-work',
            'is_published' => true,
            'access_type' => 'free',
            'file_url' => '/storage/books/paid-work.epub',
            'file_type' => 'epub',
        ]);

        Chapter::create([
            'book_id' => $book->id,
            'number' => 1,
            'position' => 1,
            'title' => 'Paid chapter',
            'body' => 'Paid body',
            'is_published' => true,
            'coin_price' => 10,
        ]);

        $this->getJson('/api/books/' . $book->slug . '/file-proxy')
            ->assertForbidden();
    }

    public function test_public_file_proxy_serves_a_locally_uploaded_free_book(): void
    {
        // Libro subido desde el Studio: el archivo vive en el disco public y
        // `file_path` apunta ahí (NO es R2). El proxy debe servirlo, no dar 500.
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('books/libro.epub', 'PK fake epub bytes');

        $book = $this->book([
            'slug' => 'free-local-epub',
            'access_type' => 'free',
            'file_path' => 'books/libro.epub',
            'file_url' => 'http://localhost:8000/storage/books/libro.epub',
            'file_type' => 'epub',
        ]);

        $res = $this->get('/api/books/' . $book->slug . '/file-proxy');

        $res->assertOk();
        $this->assertStringContainsString('epub+zip', (string) $res->headers->get('content-type'));
    }

    public function test_public_book_detail_hides_file_link_for_monetized_books(): void
    {
        $book = $this->book([
            'slug' => 'paid-detail',
            'is_published' => true,
            'access_type' => 'free',
            'file_url' => '/storage/books/paid-detail.epub',
            'file_type' => 'epub',
        ]);

        Chapter::create([
            'book_id' => $book->id,
            'number' => 1,
            'position' => 1,
            'title' => 'Paid chapter',
            'body' => 'Paid body',
            'is_published' => true,
            'coin_price' => 10,
        ]);

        $this->getJson('/api/books/' . $book->slug)
            ->assertOk()
            ->assertJsonPath('data.file_link', null);
    }

    public function test_admin_cannot_assign_admin_role_unless_super_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create();
        $target->assignRole('reader');

        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/users/' . $target->id . '/roles', [
            'role' => 'admin',
            'action' => 'assign',
        ])->assertForbidden();

        $this->assertFalse($target->fresh()->hasRole('admin'));

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');
        Sanctum::actingAs($superAdmin);

        $this->patchJson('/api/admin/users/' . $target->id . '/roles', [
            'role' => 'admin',
            'action' => 'assign',
        ])->assertOk();

        $this->assertTrue($target->fresh()->hasRole('admin'));
    }

    public function test_only_super_admin_can_grant_manual_premium_membership(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create(['subscription_tier' => 'free']);

        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/users/' . $target->id . '/membership', [
            'tier' => 'premium_plus',
        ])->assertForbidden();

        $this->assertSame('free', $target->fresh()->subscription_tier);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        Sanctum::actingAs($superAdmin);

        $this->patchJson('/api/admin/users/' . $target->id . '/membership', [
            'tier' => 'premium_plus',
        ])
            ->assertOk()
            ->assertJsonPath('subscription_tier', 'premium_plus');

        $this->assertSame('premium_plus', $target->fresh()->subscription_tier);
    }

    public function test_only_super_admin_can_grant_charcoins_to_user(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $target = User::factory()->create();

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/users/' . $target->id . '/coins', [
            'currency' => 'premium',
            'amount' => 75,
            'reason' => 'QA bonus',
        ])->assertForbidden();

        $this->assertNull(Wallet::where('user_id', $target->id)->first());

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        Sanctum::actingAs($superAdmin);

        $this->postJson('/api/admin/users/' . $target->id . '/coins', [
            'currency' => 'premium',
            'amount' => 75,
            'reason' => 'QA bonus',
        ])
            ->assertOk()
            ->assertJsonPath('currency', 'premium')
            ->assertJsonPath('amount', 75)
            ->assertJsonPath('balance_after', 75);

        $wallet = Wallet::where('user_id', $target->id)->firstOrFail();
        $this->assertSame(75, $wallet->premium_balance);

        $transaction = CoinTransaction::where('user_id', $target->id)->firstOrFail();
        $this->assertSame('admin_adjust', $transaction->type);
        $this->assertSame(75, $transaction->amount);
        $this->assertSame('QA bonus', $transaction->meta['reason']);
        $this->assertSame($superAdmin->id, $transaction->meta['admin_id']);
    }

    private function book(array $overrides = []): Book
    {
        $author = Author::create([
            'name' => 'Security Author',
            'slug' => 'security-author-' . str()->random(8),
        ]);

        return Book::create(array_merge([
            'author_id' => $author->id,
            'title' => 'Security Book ' . str()->random(8),
            'slug' => 'security-book-' . str()->random(8),
            'description' => 'Security test book.',
            'access_type' => 'free',
            'is_published' => true,
            'is_hidden' => false,
            'file_type' => 'epub',
        ], $overrides));
    }
}
