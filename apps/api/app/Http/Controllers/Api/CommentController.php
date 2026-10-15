<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\CommentLike;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Comentarios estilo WEBTOON sobre una OBRA (libro) o un CAPÍTULO (episodio).
 * Lectura pública; escribir / dar like / borrar requiere sesión.
 */
class CommentController extends Controller
{
    public function bookIndex(Request $request, Book $book): JsonResponse
    {
        return $this->list($request, $book);
    }

    public function chapterIndex(Request $request, Chapter $chapter): JsonResponse
    {
        return $this->list($request, $chapter);
    }

    public function bookStore(Request $request, Book $book): JsonResponse
    {
        return $this->create($request, $book);
    }

    public function chapterStore(Request $request, Chapter $chapter): JsonResponse
    {
        return $this->create($request, $chapter);
    }

    /** GET respuestas de un comentario de primer nivel. */
    public function replies(Request $request, Comment $comment): JsonResponse
    {
        $replies = $comment->replies()->with('user')->get();
        $this->markLiked($request, $replies);

        return response()->json(['data' => CommentResource::collection($replies)]);
    }

    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $user = $request->user();
        $isOwner = $comment->user_id === $user->id;
        $isAdmin = $user->hasAnyRole(['admin', 'super_admin']);
        if (! $isOwner && ! $isAdmin) {
            abort(403, 'No puedes borrar este comentario.');
        }

        if ($comment->parent_id) {
            Comment::where('id', $comment->parent_id)->where('replies_count', '>', 0)->decrement('replies_count');
        }
        $comment->delete();

        return response()->json(['message' => 'Comentario eliminado.']);
    }

    public function like(Request $request, Comment $comment): JsonResponse
    {
        $like = CommentLike::firstOrCreate(
            ['comment_id' => $comment->id, 'user_id' => $request->user()->id],
            ['created_at' => now()],
        );
        if ($like->wasRecentlyCreated) {
            $comment->increment('likes_count');
        }

        return response()->json(['liked' => true, 'likes_count' => (int) $comment->fresh()->likes_count]);
    }

    public function unlike(Request $request, Comment $comment): JsonResponse
    {
        $deleted = CommentLike::where('comment_id', $comment->id)
            ->where('user_id', $request->user()->id)
            ->delete();
        if ($deleted) {
            Comment::where('id', $comment->id)->where('likes_count', '>', 0)->decrement('likes_count');
        }

        return response()->json(['liked' => false, 'likes_count' => (int) $comment->fresh()->likes_count]);
    }

    // ───────────────────────── helpers ─────────────────────────

    private function list(Request $request, Model $commentable): JsonResponse
    {
        $sort = $request->query('sort') === 'newest' ? 'newest' : 'top';

        $query = $commentable->comments()->whereNull('parent_id')->with('user');
        $query = $sort === 'newest'
            ? $query->orderByDesc('created_at')
            : $query->orderByDesc('likes_count')->orderByDesc('created_at');

        $page = $query->paginate(20);
        $this->markLiked($request, $page->getCollection());

        return response()->json([
            'data' => CommentResource::collection($page->items()),
            'total' => $page->total(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    private function create(Request $request, Model $commentable): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'is_spoiler' => ['sometimes', 'boolean'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = Comment::find($data['parent_id']);
            // El padre debe existir, ser de primer nivel y del mismo destino.
            if (! $parent
                || $parent->parent_id !== null
                || $parent->commentable_type !== $commentable->getMorphClass()
                || (int) $parent->commentable_id !== (int) $commentable->getKey()) {
                throw ValidationException::withMessages(['parent_id' => ['Respuesta inválida.']]);
            }
        }

        $comment = $commentable->comments()->create([
            'user_id' => $request->user()->id,
            'parent_id' => $parent?->id,
            'body' => trim($data['body']),
            'is_spoiler' => (bool) ($data['is_spoiler'] ?? false),
        ]);

        if ($parent) {
            $parent->increment('replies_count');
        }

        $comment->load('user');
        $comment->liked_by_me = false;

        return response()->json(['data' => new CommentResource($comment)], 201);
    }

    /** Marca `liked_by_me` en cada comentario para el usuario autenticado. */
    private function markLiked(Request $request, $comments): void
    {
        $user = $request->user();
        if (! $user || $comments->isEmpty()) {
            return;
        }
        $liked = CommentLike::whereIn('comment_id', $comments->pluck('id'))
            ->where('user_id', $user->id)
            ->pluck('comment_id')
            ->flip();

        foreach ($comments as $c) {
            $c->liked_by_me = $liked->has($c->id);
        }
    }
}
