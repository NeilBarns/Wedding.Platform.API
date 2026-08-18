<?php

namespace App\Http\Resources;

use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $definition = app(WebsiteSectionRegistry::class)->get($this->type);
        $template = $this->relationLoaded('website')
            ? app(WebsiteTemplateRegistry::class)->get($this->website->template_key)
            : null;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'displayName' => $definition?->displayName ?? $this->type,
            'sortOrder' => $this->sort_order,
            'isEnabled' => $this->is_enabled,
            'content' => $this->content,
            'appearance' => $this->appearance,
            'appearanceOptions' => $template?->appearanceOptionsFor($this->type),
            'mediaCapability' => $template?->mediaCapabilityFor($this->type),
        ];
    }
}
