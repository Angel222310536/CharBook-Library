<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Versión ligera de una antología (listas, grids, sección del perfil).
 * Espera withCount('books') y, opcionalmente, books recortados para portada.
 */
class AnthologyCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user('sanctum');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'is_public' => $this->is_public,
            'books_count' => (int) ($this->books_count ?? $this->books?->count() ?? 0),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'is_mine' => $viewer?->id === $this->user_id,

            // Portadas para el montaje visual (primeros libros)
            'cover_books' => $this->whenLoaded('books', fn () =>
                $this->books->take(4)->map(fn ($b) => $b->cover)->filter()->values()
            ),

            'owner' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'avatar' => $this->user->avatar,
            ]),
        ];
    }
}
