<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user('sanctum');
        $isStaff = $viewer && $viewer->hasAnyRole(['admin', 'super_admin', 'moderator']);

        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'title' => $this->title,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'username' => $this->user->username,
                'avatar' => $this->user->avatar,
                'is_creator' => $this->user->creator_status === 'approved'
                    && $this->user->hasRole('creator'),
                'creator_level' => $this->user->creator_level,
                'is_premium_plus' => \App\Services\Entitlements::isPremiumPlus($this->user),
            ]),
            'is_mine' => $viewer?->id === $this->user_id,
            // Si el espectador es staff (admin/super/mod), puede borrar la reseña
            'can_delete_as_staff' => $isStaff,
        ];
    }
}
