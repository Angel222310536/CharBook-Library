<?php

namespace App\Http\Resources;

use App\Models\AffiliateLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliateLinkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendor' => $this->vendor,
            'vendor_label' => AffiliateLink::VENDOR_LABELS[$this->vendor] ?? $this->vendor,
            'url' => $this->url,
            'label' => $this->label,
            'position' => $this->position,
            'is_active' => $this->is_active,
            'click_count' => $this->click_count,
            // Endpoint para trackear click + redirigir
            'go_url' => url("/api/affiliate-links/{$this->id}/go"),
        ];
    }
}
