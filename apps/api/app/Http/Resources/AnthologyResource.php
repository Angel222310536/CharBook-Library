<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Antología completa con sus libros ordenados (vista de detalle).
 */
class AnthologyResource extends JsonResource
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
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'is_mine' => $viewer?->id === $this->user_id,

            'owner' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'avatar' => $this->user->avatar,
            ]),

            // Libros con nota curatorial + posición (pivot)
            'books' => $this->whenLoaded('books', fn () =>
                $this->books->map(fn ($book) => [
                    'book' => new BookListResource($book),
                    'note' => $book->pivot->note,
                    'position' => (int) $book->pivot->position,
                ])->values()
            ),
        ];
    }
}
