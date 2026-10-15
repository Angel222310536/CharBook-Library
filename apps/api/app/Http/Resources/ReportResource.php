<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'reason' => $this->reason,
            'details' => $this->details,
            'status' => $this->status,
            'resolution_action' => $this->resolution_action,
            'resolution_notes' => $this->resolution_notes,
            'reporter' => $this->whenLoaded('reporter', fn() => [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
                'username' => $this->reporter->username,
                'avatar' => $this->reporter->avatar,
            ]),
            'resolver' => $this->whenLoaded('resolver', fn() => $this->resolver ? [
                'id' => $this->resolver->id,
                'name' => $this->resolver->name,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
