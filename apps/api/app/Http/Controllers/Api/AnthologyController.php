<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnthologyCardResource;
use App\Http\Resources\AnthologyResource;
use App\Models\Anthology;
use App\Models\Book;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AnthologyController extends Controller
{
    /** Asegura que el usuario actual es el dueño de la antología. */
    private function authorizeOwner(Request $request, Anthology $anthology): void
    {
        if ($request->user()?->id !== $anthology->user_id) {
            throw new AccessDeniedHttpException('No es tu antología.');
        }
    }

    /**
     * GET /api/me/anthologies
     * Mis antologías (incluye privadas).
     */
    public function mine(Request $request): JsonResponse
    {
        $anthologies = $request->user()->anthologies()
            ->withCount('books')
            ->with(['books' => fn ($q) => $q->limit(4)])
            ->latest('updated_at')
            ->get();

        return AnthologyCardResource::collection($anthologies)->response();
    }

    /**
     * GET /api/users/{username}/anthologies
     * Antologías públicas de un lector (o todas si soy yo).
     */
    public function forUser(Request $request, User $user): JsonResponse
    {
        $isOwner = $request->user('sanctum')?->id === $user->id;

        $anthologies = $user->anthologies()
            ->when(! $isOwner, fn ($q) => $q->where('is_public', true))
            ->withCount('books')
            ->with(['books' => fn ($q) => $q->limit(4), 'user:id,name,username,avatar'])
            ->latest('updated_at')
            ->get();

        return AnthologyCardResource::collection($anthologies)->response();
    }

    /**
     * GET /api/anthologies/{slug}
     * Detalle de una antología (pública o propia).
     */
    public function show(Request $request, Anthology $anthology): JsonResponse
    {
        $isOwner = $request->user('sanctum')?->id === $anthology->user_id;
        if (! $anthology->is_public && ! $isOwner) {
            throw new AccessDeniedHttpException('Esta antología es privada.');
        }

        $anthology->loadCount('books')->load([
            'user:id,name,username,avatar',
            'books' => fn ($q) => $q->with('author:id,name,slug'),
        ]);

        return (new AnthologyResource($anthology))->response();
    }

    /**
     * POST /api/anthologies
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'is_public' => 'sometimes|boolean',
        ]);

        $anthology = $request->user()->anthologies()->create($data);
        $anthology->loadCount('books');

        return (new AnthologyResource($anthology))->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/anthologies/{slug}
     */
    public function update(Request $request, Anthology $anthology): JsonResponse
    {
        $this->authorizeOwner($request, $anthology);

        $data = $request->validate([
            'title' => 'sometimes|string|max:120',
            'description' => 'sometimes|nullable|string|max:1000',
            'is_public' => 'sometimes|boolean',
        ]);

        $anthology->update($data);
        $anthology->loadCount('books')->load([
            'user:id,name,username,avatar',
            'books' => fn ($q) => $q->with('author:id,name,slug'),
        ]);

        return (new AnthologyResource($anthology))->response();
    }

    /**
     * DELETE /api/anthologies/{slug}
     */
    public function destroy(Request $request, Anthology $anthology): JsonResponse
    {
        $this->authorizeOwner($request, $anthology);
        $anthology->delete();

        return response()->json(['message' => 'Antología eliminada.']);
    }

    /**
     * POST /api/anthologies/{slug}/books
     * Añade un libro al final de la antología.
     */
    public function addBook(Request $request, Anthology $anthology): JsonResponse
    {
        $this->authorizeOwner($request, $anthology);

        $data = $request->validate([
            'book_id' => 'required|integer|exists:books,id',
            'note' => 'nullable|string|max:500',
        ]);

        if ($anthology->books()->where('book_id', $data['book_id'])->exists()) {
            return response()->json(['message' => 'Ese libro ya está en la antología.'], 422);
        }

        $nextPosition = (int) $anthology->books()->max('anthology_book.position') + 1;

        $anthology->books()->attach($data['book_id'], [
            'position' => $nextPosition,
            'note' => $data['note'] ?? null,
        ]);

        $anthology->loadCount('books')->load([
            'user:id,name,username,avatar',
            'books' => fn ($q) => $q->with('author:id,name,slug'),
        ]);

        return (new AnthologyResource($anthology))->response()->setStatusCode(201);
    }

    /**
     * DELETE /api/anthologies/{slug}/books/{book}
     */
    public function removeBook(Request $request, Anthology $anthology, Book $book): JsonResponse
    {
        $this->authorizeOwner($request, $anthology);
        $anthology->books()->detach($book->id);

        $anthology->loadCount('books')->load([
            'user:id,name,username,avatar',
            'books' => fn ($q) => $q->with('author:id,name,slug'),
        ]);

        return (new AnthologyResource($anthology))->response();
    }
}
