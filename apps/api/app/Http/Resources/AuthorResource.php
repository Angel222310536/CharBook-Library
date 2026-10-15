<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'biography' => $this->biography,
            'photo_url' => $this->photo_url,
            'birth_year' => $this->birth_year,
            'death_year' => $this->death_year,
            'nationality' => $this->nationality,
            'books_count' => $this->whenCounted('books'),
        ];
    }
}
