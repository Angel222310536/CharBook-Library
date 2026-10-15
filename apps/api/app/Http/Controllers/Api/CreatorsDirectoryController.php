<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Directorio público de creadores de CharBook.
 *
 * Endpoints:
 *   GET /api/creators           → secciones de creadores agrupadas por tier
 *   GET /api/creators/books     → libros de creadores con filtro por categoría
 */
class CreatorsDirectoryController extends Controller
{
    /**
     * GET /api/creators
     * Devuelve creadores agrupados por tier para la landing del directorio.
     */
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user('sanctum');

        // FEATURED — manualmente curados por staff
        $featured = User::query()
            ->where('creator_tier', 'featured')
            ->where('is_public_profile', true)
            ->withCount(['followers', 'booksAsCreator'])
            ->orderByDesc('followers_count')
            ->limit(6)
            ->get()
            ->map(fn ($u) => $this->serializeCreator($u, $viewer));

        // VERIFIED — creator_status approved + rol creator (excluyendo featured)
        $verified = User::query()
            ->where('creator_status', 'approved')
            ->whereHas('roles', fn ($q) => $q->where('name', 'creator'))
            ->where('creator_tier', '!=', 'featured')
            ->where('is_public_profile', true)
            ->withCount(['followers', 'booksAsCreator'])
            ->orderByDesc('followers_count')
            ->limit(12)
            ->get()
            ->map(fn ($u) => $this->serializeCreator($u, $viewer));

        // OTHERS — basic: tiene libros publicados pero no es verified ni featured
        $basic = User::query()
            ->where('is_public_profile', true)
            ->where(function ($q) {
                $q->where('creator_status', '!=', 'approved')
                    ->orWhereDoesntHave('roles', fn ($q2) => $q2->where('name', 'creator'));
            })
            ->where('creator_tier', '!=', 'featured')
            ->whereHas('booksAsCreator', fn ($q) => $q->where('is_published', true))
            ->withCount(['followers', 'booksAsCreator'])
            ->orderByDesc('books_as_creator_count')
            ->limit(20)
            ->get()
            ->map(fn ($u) => $this->serializeCreator($u, $viewer));

        return response()->json([
            'featured' => $featured->values(),
            'verified' => $verified->values(),
            'basic' => $basic->values(),
        ]);
    }

    /**
     * GET /api/creators/books?category=fantasia
     * Libros publicados exclusivamente por creadores (no admin uploads),
     * con filtro opcional por slug de categoría.
     */
    public function books(Request $request): JsonResponse
    {
        $categorySlug = $request->query('category');
        $page = (int) $request->query('page', 1);

        $query = Book::query()
            ->where('is_published', true)
            ->whereNotNull('creator_id') // solo obras subidas por creadores
            ->with([
                'author:id,name,slug',
                'creator:id,name,username,avatar,creator_status,creator_tier',
                'creator.roles:id,name',
            ]);

        if ($categorySlug) {
            $query->whereHas(
                'categories',
                fn (Builder $q) => $q->where('slug', $categorySlug)
            );
        }

        $books = $query
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->paginate(24, page: $page)
            ->withQueryString();

        return response()->json([
            'data' => $books->getCollection()->map(fn (Book $b) => [
                'id' => $b->id,
                'title' => $b->title,
                'slug' => $b->slug,
                'cover' => $b->cover,
                'access_type' => $b->access_type,
                'language' => $b->language,
                'rating_average' => (float) $b->rating_average,
                'rating_count' => (int) $b->rating_count,
                'is_featured' => (bool) $b->is_featured,
                'creator' => $b->creator ? [
                    'id' => $b->creator->id,
                    'name' => $b->creator->name,
                    'username' => $b->creator->username,
                    'avatar' => $b->creator->avatar,
                    'creator_level' => $b->creator->creator_level,
                ] : null,
            ]),
            'meta' => [
                'current_page' => $books->currentPage(),
                'last_page' => $books->lastPage(),
                'per_page' => $books->perPage(),
                'total' => $books->total(),
            ],
        ]);
    }

    /**
     * GET /api/creators/categories
     * Solo las categorías que tienen al menos una obra de creador.
     */
    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->whereHas('books', fn ($q) => $q->where('is_published', true)->whereNotNull('creator_id'))
            ->withCount(['books' => fn ($q) => $q->where('is_published', true)->whereNotNull('creator_id')])
            ->orderByDesc('books_count')
            ->limit(20)
            ->get(['id', 'name', 'slug']);

        return response()->json(['data' => $categories]);
    }

    private function serializeCreator(User $u, ?User $viewer): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username,
            'avatar' => $u->avatar,
            'bio' => $u->bio,
            'creator_level' => $u->creator_level,
            'stats' => [
                'followers' => (int) ($u->followers_count ?? 0),
                'books' => (int) ($u->books_as_creator_count ?? 0),
            ],
            'is_following' => $viewer?->isFollowing($u) ?? false,
            'is_me' => $viewer?->id === $u->id,
        ];
    }
}
