<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una actividad del feed de la comunidad.
 * Espera load('user', 'book.author') y, para el estado del espectador,
 * withExists o pivots cargados (lo prepara FeedController).
 */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'meta' => $this->meta,
            'created_at' => $this->created_at?->toIso8601String(),

            'likes_count' => (int) $this->likes_count,
            'comments_count' => (int) $this->comments_count,
            'shares_count' => (int) $this->shares_count,

            // Estado del espectador (lo inyecta el controller vía withExists)
            'liked_by_me' => (bool) ($this->liked_by_exists ?? false),
            'shared_by_me' => (bool) ($this->shared_by_exists ?? false),

            'actor' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'avatar' => $this->user->avatar,
                'is_premium_plus' => \App\Services\Entitlements::isPremiumPlus($this->user),
            ]),

            'book' => $this->whenLoaded('book', fn () =>
                $this->book ? new BookListResource($this->book) : null
            ),
        ];
    }
}
