<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthorResource;
use App\Http\Resources\BookListResource;
use App\Models\Author;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorController extends Controller
{
    public function index(): JsonResponse
    {
        $authors = Author::withCount(['books' => fn($q) => $q->where('is_published', true)])
            ->orderBy('name')
            ->get();
        return AuthorResource::collection($authors)->response();
    }

    public function show(Author $author): JsonResponse
    {
        $author->loadCount(['books' => fn($q) => $q->where('is_published', true)]);
        $author->load(['books' => fn($q) => $q->where('is_published', true)->with('author')]);

        return response()->json([
            'data' => new AuthorResource($author),
            'books' => BookListResource::collection($author->books),
        ]);
    }

    // ===== Admin =====

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'biography' => 'nullable|string',
            'photo_url' => 'nullable|url|max:500',
            'birth_year' => 'nullable|integer|between:-3000,2100',
            'death_year' => 'nullable|integer|between:-3000,2100',
            'nationality' => 'nullable|string|max:80',
        ]);
        return (new AuthorResource(Author::create($data)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Author $author): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'biography' => 'nullable|string',
            'photo_url' => 'nullable|url|max:500',
            'birth_year' => 'nullable|integer|between:-3000,2100',
            'death_year' => 'nullable|integer|between:-3000,2100',
            'nationality' => 'nullable|string|max:80',
        ]);
        $author->update($data);
        return (new AuthorResource($author))->response();
    }

    public function destroy(Author $author): JsonResponse
    {
        if ($author->books()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: el autor tiene libros asociados.',
            ], 422);
        }
        $author->delete();
        return response()->json(['message' => 'Autor eliminado.']);
    }
}
