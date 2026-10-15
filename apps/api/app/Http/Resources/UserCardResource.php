<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Versión ligera de un usuario para listados (seguidores, seguidos,
 * autores de reviews). No expone email ni datos sensibles.
 */
class UserCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Guard 'sanctum' explícito: estas rutas son públicas (no van detrás de
        // auth:sanctum) pero el front envía Bearer, así resolvemos al espectador.
        $viewer = $request->user('sanctum');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            // Si hay sesión: ¿el usuario actual ya sigue a esta persona?
            'is_following' => $viewer?->isFollowing($this->resource) ?? false,
            'is_me' => $viewer?->id === $this->id,
            // Tier visual (none | basic | verified | featured)
            'creator_level' => $this->creator_level,
        ];
    }
}
