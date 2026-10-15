<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource ligero para listados (grids, carruseles, paginación).
 */
class BookListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'cover' => $this->cover, // accesor del modelo
            'access_type' => $this->access_type,
            'language' => $this->language,
            'publication_year' => $this->publication_year,
            'rating_average' => (float) $this->rating_average,
            'rating_count' => $this->rating_count,
            'is_featured' => $this->is_featured,
            'author' => $this->whenLoaded('author', fn() => [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'slug' => $this->author->slug,
            ]),
        ];
    }
}
