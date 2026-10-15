<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CommentsTest extends TestCase
{
    use RefreshDatabase;

    private function book(): Book
    {
        return Book::create([
            'creator_id' => User::factory()->create()->id,
            'title' => 'Obra',
            'slug' => 'obra-'.uniqid(),
            'description' => 'x',
            'work_format' => 'comic',
            'is_published' => true,
            'submission_status' => 'approved',
            'language' => 'es',
        ]);
    }

    public function test_user_can_post_and_list_comments(): void
    {
        $book = $this->book();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/books/{$book->slug}/comments", ['body' => 'Gran capítulo!'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Gran capítulo!');

        $this->getJson("/api/books/{$book->slug}/comments")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.user.id', $user->id);
    }

    public function test_replies_increment_parent_count(): void
    {
        $book = $this->book();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $parentId = $this->postJson("/api/books/{$book->slug}/comments", ['body' => 'padre'])
            ->json('data.id');

        $this->postJson("/api/books/{$book->slug}/comments", ['body' => 'respuesta', 'parent_id' => $parentId])
            ->assertCreated();

        $this->assertSame(1, Comment::find($parentId)->replies_count);
        // El listado de primer nivel NO incluye la respuesta.
        $this->getJson("/api/books/{$book->slug}/comments")->assertJsonPath('total', 1);
        // Las respuestas se piden aparte.
        $this->getJson("/api/comments/{$parentId}/replies")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_like_and_unlike_a_comment(): void
    {
        $book = $this->book();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $id = $this->postJson("/api/books/{$book->slug}/comments", ['body' => 'x'])->json('data.id');

        $this->postJson("/api/comments/{$id}/like")->assertOk()->assertJsonPath('likes_count', 1);
        // Idempotente: segundo like no duplica.
        $this->postJson("/api/comments/{$id}/like")->assertOk()->assertJsonPath('likes_count', 1);
        $this->deleteJson("/api/comments/{$id}/like")->assertOk()->assertJsonPath('likes_count', 0);
    }

    public function test_only_owner_or_admin_can_delete(): void
    {
        $book = $this->book();
        $author = User::factory()->create();
        Sanctum::actingAs($author);
        $id = $this->postJson("/api/books/{$book->slug}/comments", ['body' => 'mío'])->json('data.id');

        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson("/api/comments/{$id}")->assertForbidden();

        Sanctum::actingAs($author);
        $this->deleteJson("/api/comments/{$id}")->assertOk();
        $this->getJson("/api/books/{$book->slug}/comments")->assertJsonPath('total', 0);
    }

    public function test_super_admin_can_delete_any_comment(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $book = $this->book();
        $author = User::factory()->create();
        Sanctum::actingAs($author);
        $id = $this->postJson("/api/books/{$book->slug}/comments", ['body' => 'revisar'])
            ->json('data.id');

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');
        Sanctum::actingAs($superAdmin);

        $this->deleteJson("/api/comments/{$id}")->assertOk();
        $this->assertNull(Comment::find($id));
    }

    public function test_guests_can_read_but_not_post(): void
    {
        $book = $this->book();
        $this->getJson("/api/books/{$book->slug}/comments")->assertOk();
        $this->postJson("/api/books/{$book->slug}/comments", ['body' => 'x'])->assertUnauthorized();
    }
}
