<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Perfil público "Atelier" de un lector / creador.
 *
 * Espera que el modelo venga con:
 *   - withCount(['followers','following','favorites','reviews','currentlyReading','readBooks'])
 *   - Si es creador: load('booksAsCreator') (libros publicados por él)
 *   - load('favoriteBooks', 'currentlyReading', 'readBooks', 'reviews', 'anthologies')
 * Lo prepara ProfileController@show.
 */
class PublicProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user('sanctum');

        $isCreator = $this->creator_status === 'approved' && $this->hasRole('creator');
        $isMe = $viewer?->id === $this->id;

        // Estado de follow para el botón con 4 caras
        $isFollowing = $viewer && ! $isMe
            ? $viewer->isFollowing($this->resource)
            : false;

        $hasPending = $viewer && ! $isMe && ! $isFollowing && ! $this->is_public_profile
            ? $viewer->hasPendingRequestTo($this->resource)
            : false;

        $followState = match (true) {
            $isMe => 'self',
            $isFollowing => 'following',
            $hasPending => 'pending',
            default => 'none',
        };

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            'member_since' => $this->created_at?->toIso8601String(),

            // Privacidad / creador
            'is_public_profile' => (bool) $this->is_public_profile,
            'is_creator' => $isCreator,
            'creator_level' => $this->creator_level,
            'creator_badge' => $isCreator ? [
                'label' => 'Creador',
                'tier' => 'approved',
            ] : null,

            // Insignia Premium Plus (H) + perfil avanzado (F) — banner oficial.
            'is_premium_plus' => \App\Services\Entitlements::isPremiumPlus($this->resource),
            'profile_banner' => data_get($this->preferences, 'profile_banner'),

            'stats' => [
                'followers' => (int) ($this->followers_count ?? 0),
                'following' => (int) ($this->following_count ?? 0),
                'favorites' => (int) ($this->favorites_count ?? 0),
                'reviews' => (int) ($this->reviews_count ?? 0),
                'reading' => (int) ($this->currently_reading_count ?? 0),
                'read' => (int) ($this->read_books_count ?? 0),
                'books_as_creator' => (int) ($this->books_as_creator_count ?? 0),
            ],

            // Relación con el espectador
            'is_me' => $isMe,
            'is_following' => $isFollowing,
            'has_pending_request' => $hasPending,
            'follow_state' => $followState, // self | following | pending | none

            // Vitrina pública
            'favorite_books' => BookListResource::collection(
                $this->whenLoaded('favoriteBooks')
            ),

            'currently_reading' => BookListResource::collection(
                $this->whenLoaded('currentlyReading')
            ),
            'read_books' => BookListResource::collection(
                $this->whenLoaded('readBooks')
            ),

            'anthologies' => AnthologyCardResource::collection(
                $this->whenLoaded('anthologies')
            ),

            // Obras publicadas por este creador (solo cargado cuando is_creator)
            'creator_books' => BookListResource::collection(
                $this->whenLoaded('booksAsCreator')
            ),

            // Widgets de creador agrupados por tipo (solo si is_creator + publicados)
            'creator_widgets' => $this->whenLoaded('creatorWidgets', fn () =>
                $this->creatorWidgets
                    ->groupBy('type')
                    ->map(fn ($items) => $items->map(fn ($w) => [
                        'id' => $w->id,
                        'type' => $w->type,
                        'title' => $w->title,
                        'body' => $w->body,
                        'url' => $w->url,
                        'starts_at' => $w->starts_at?->toIso8601String(),
                        'ends_at' => $w->ends_at?->toIso8601String(),
                        'position' => $w->position,
                        'book' => $w->book ? [
                            'id' => $w->book->id,
                            'title' => $w->book->title,
                            'slug' => $w->book->slug,
                            'cover' => $w->book->cover,
                        ] : null,
                        'category' => $w->category ? [
                            'id' => $w->category->id,
                            'name' => $w->category->name,
                            'slug' => $w->category->slug,
                        ] : null,
                    ])->values())
            ),

            // Reviews recientes con el libro embebido
            'recent_reviews' => $this->whenLoaded('reviews', fn () =>
                $this->reviews->map(fn ($review) => [
                    'id' => $review->id,
                    'rating' => $review->rating,
                    'title' => $review->title,
                    'comment' => $review->comment,
                    'created_at' => $review->created_at?->toIso8601String(),
                    'book' => $review->relationLoaded('book') && $review->book
                        ? new BookListResource($review->book)
                        : null,
                ])->values()
            ),
        ];
    }
}
