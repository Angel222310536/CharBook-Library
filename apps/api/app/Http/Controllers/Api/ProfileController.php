<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicProfileResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /**
     * GET /api/users/{username}
     * Perfil público "Atelier" de un lector.
     *
     * Si el perfil es privado y no eres tú, devolvemos sólo lo básico
     * (sin vitrina de favoritos/reviews) con is_private = true.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        // Ruta pública: el espectador llega vía Bearer (guard sanctum).
        $isOwner = $request->user('sanctum')?->id === $user->id;
        $canSeeShowcase = $user->is_public_profile || $isOwner;

        $user->loadCount([
            'followers', 'following', 'favorites', 'reviews',
            'currentlyReading', 'readBooks',
            'booksAsCreator',
        ]);

        $isCreatorApproved = $user->creator_status === 'approved' && $user->hasRole('creator');

        // Las obras del creador SIEMPRE son visibles aunque el perfil sea privado:
        // un creador necesita que su catálogo se pueda ver.
        if ($isCreatorApproved) {
            $user->load([
                'booksAsCreator' => fn ($q) => $q
                    ->where('is_published', true)
                    ->with('author:id,name,slug')
                    ->latest()
                    ->limit(12),
                'creatorWidgets' => fn ($q) => $q
                    ->where('is_published', true)
                    ->with(['book:id,title,slug,cover_url,cover_path', 'category:id,name,slug'])
                    ->orderBy('type')
                    ->orderBy('position')
                    ->orderByDesc('created_at'),
            ]);
        }

        if ($canSeeShowcase) {
            $user->load([
                'currentlyReading' => fn ($q) => $q
                    ->where('is_published', true)
                    ->with('author:id,name,slug')
                    ->limit(8),
                'readBooks' => fn ($q) => $q
                    ->where('is_published', true)
                    ->with('author:id,name,slug')
                    ->limit(12),
                'favoriteBooks' => fn ($q) => $q
                    ->where('is_published', true)
                    ->with('author:id,name,slug')
                    ->orderByDesc('favorites.created_at')
                    ->limit(12),
                'reviews' => fn ($q) => $q
                    ->with(['book:id,title,slug,cover_url,cover_path,access_type,language,publication_year,rating_average,rating_count,is_featured,author_id', 'book.author:id,name,slug'])
                    ->latest()
                    ->limit(6),
                'anthologies' => fn ($q) => $q
                    ->when(! $isOwner, fn ($q) => $q->where('is_public', true))
                    ->withCount('books')
                    ->with(['books' => fn ($q) => $q->limit(4)])
                    ->latest('updated_at')
                    ->limit(6),
            ]);
        }

        return (new PublicProfileResource($user))
            ->additional(['meta' => ['is_private' => ! $canSeeShowcase]])
            ->response();
    }

    /**
     * PATCH /api/me/profile
     * Actualiza MI perfil (username, bio, visibilidad).
     */
    public function updateMine(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'username' => [
                'sometimes',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_public_profile' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', 'string', 'in:es,en,pt,fr,de,it,ja'],
        ], [
            'username.regex' => 'El username sólo admite letras, números y guion bajo.',
            'username.unique' => 'Ese username ya está en uso.',
        ]);

        $user->fill($data)->save();
        $user->loadCount(['followers', 'following', 'favorites', 'reviews']);

        return (new PublicProfileResource($user))->response();
    }
}
