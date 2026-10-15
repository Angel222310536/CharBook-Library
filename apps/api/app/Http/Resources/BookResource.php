<?php

namespace App\Http\Resources;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;

/**
 * Resource completo para la vista de detalle.
 * Incluye estado social del usuario actual (is_favorited, my_review) cuando hay auth.
 *
 * Cada bloque social está envuelto en try/catch para que si una tabla no
 * existe o la relación falla, el detalle del libro NO se rompa.
 */
class BookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user('sanctum');
        $canViewPrivateStats = $user && (
            (int) $this->creator_id === (int) $user->id
            || $user->hasAnyRole(['admin', 'super_admin'])
        );

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'synopsis' => $this->synopsis,
            'cover' => $this->cover,
            'file_link' => $this->publicFileLink(),
            'file_type' => $this->file_type,
            'file_size' => $this->file_size,
            'access_type' => $this->access_type,
            'allow_download' => (bool) $this->allow_download,
            'price' => $this->price,
            'isbn_10' => $this->isbn_10,
            'isbn_13' => $this->isbn_13,
            'publisher' => $this->publisher,
            'publication_year' => $this->publication_year,
            'language' => $this->language,
            'pages' => $this->pages,
            'views_count' => $this->views_count,
            'unique_readers_count' => (int) $this->views_count,
            'total_readings_count' => $this->when($canViewPrivateStats, (int) $this->total_readings_count),
            'downloads_count' => $this->downloads_count,
            'favorites_count' => $this->favorites_count,
            'rating_average' => (float) $this->rating_average,
            'rating_count' => $this->rating_count,
            'is_published' => $this->is_published,
            'is_featured' => $this->is_featured,
            'created_at' => $this->created_at?->toIso8601String(),
            'author' => new AuthorResource($this->whenLoaded('author')),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'affiliate_links' => AffiliateLinkResource::collection($this->whenLoaded('affiliateLinks')),

            // Wizard fields para el detalle / lector
            'work_format' => $this->work_format,
            'age_rating' => $this->age_rating,
            'tags' => $this->tags ?? [],
            'author_note_start' => $this->author_note_start,
            'author_note_end' => $this->author_note_end,
            'chapters_count' => (int) ($this->chapters()->where('is_published', true)->count()),
            'pages_count' => (int) ($this->comicPages()->count()),
            'creator' => $this->whenLoaded('creator', fn () => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
                'username' => $this->creator->username,
                'avatar' => $this->creator->avatar,
                'creator_level' => $this->creator->creator_level ?? 'none',
            ]),

            // ===== Estado social del usuario actual (defensivo) =====
            'is_favorited' => $this->safeFavorited($user),
            'my_status' => $this->safeStatus($user),
            'my_review' => $this->safeMyReview($user),
        ];
    }

    private function safeFavorited($user): bool
    {
        if (! $user) return false;
        try {
            return $user->favorites()->where('book_id', $this->id)->exists();
        } catch (\Throwable $e) {
            Log::warning('BookResource safeFavorited failed: ' . $e->getMessage());
            return false;
        }
    }

    private function publicFileLink(): ?string
    {
        if ($this->access_type !== 'free' || $this->in_unlimited_catalog) {
            return null;
        }

        try {
            $hasPaidChapters = $this->chapters()
                ->where('is_published', true)
                ->where('coin_price', '>', 0)
                ->exists();
        } catch (\Throwable $e) {
            Log::warning('BookResource publicFileLink failed: ' . $e->getMessage());
            return null;
        }

        return $hasPaidChapters ? null : $this->file_link;
    }

    private function safeStatus($user): ?string
    {
        if (! $user) return null;
        try {
            return optional(
                $user->shelf()->where('book_id', $this->id)->first()
            )->pivot->status;
        } catch (\Throwable $e) {
            Log::warning('BookResource safeStatus failed: ' . $e->getMessage());
            return null;
        }
    }

    private function safeMyReview($user): ?array
    {
        if (! $user) return null;
        try {
            $review = Review::where('user_id', $user->id)
                ->where('book_id', $this->id)
                ->first();
            return $review ? [
                'id' => $review->id,
                'rating' => $review->rating,
                'title' => $review->title,
                'comment' => $review->comment,
            ] : null;
        } catch (\Throwable $e) {
            Log::warning('BookResource safeMyReview failed: ' . $e->getMessage());
            return null;
        }
    }
}
