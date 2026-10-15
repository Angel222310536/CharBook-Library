<?php

namespace App\Http\Resources;

use App\Services\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'is_spoiler' => (bool) $this->is_spoiler,
            'likes_count' => (int) $this->likes_count,
            'replies_count' => (int) $this->replies_count,
            'liked_by_me' => (bool) ($this->liked_by_me ?? false),
            'parent_id' => $this->parent_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'avatar' => $this->user->avatar_url ?? null,
                'is_creator' => $this->user->creator_status === 'approved' && $this->user->hasRole('creator'),
                'creator_level' => $this->user->creator_level,
                'is_premium_plus' => Entitlements::isPremiumPlus($this->user),
            ]),
        ];
    }
}
