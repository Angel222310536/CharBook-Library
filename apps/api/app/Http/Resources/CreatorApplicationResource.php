<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'display_name' => $this->display_name,
            'pen_name' => $this->pen_name,
            'bio_long' => $this->bio_long,
            'sample_url' => $this->sample_url,
            'genres' => $this->genres,
            'languages' => $this->languages,
            'why_join' => $this->why_join,
            'social_links' => $this->social_links,
            'publish_frequency' => $this->publish_frequency,
            'publish_plan' => $this->publish_plan,

            // Scoring (solo visible para revisor o si ya resuelto)
            'score' => [
                'quality' => $this->score_quality,
                'commitment' => $this->score_commitment,
                'professionalism' => $this->score_professionalism,
                'fit' => $this->score_fit,
                'total' => $this->score_total,
                'max' => 12,
                'min_to_approve' => 8,
            ],

            'reviewer_notes' => $this->reviewer_notes,
            'reviewer' => $this->whenLoaded('reviewer', fn() => [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
                'username' => $this->reviewer->username,
            ]),

            'user' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'email' => $this->user->email,
                'avatar' => $this->user->avatar,
                'created_at' => $this->user->created_at?->toIso8601String(),
                'reviews_count' => $this->user->reviews()->count(),
                'anthologies_count' => $this->user->anthologies()->count(),
                'followers_count' => $this->user->followers()->count(),
                'creator_status' => $this->user->creator_status ?? 'none',
                'creator_tier' => $this->user->creator_tier ?? 'none',
                'creator_level' => $this->user->creator_level,
            ]),

            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
        ];
    }
}
