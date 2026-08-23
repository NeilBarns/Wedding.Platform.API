<?php

namespace App\Http\Resources;

use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteSectionResource extends JsonResource
{
    /** @param array<string, mixed> $normalizedContent */
    public function __construct($resource, private readonly array $normalizedContent)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $definition = app(WebsiteSectionRegistry::class)->get($this->type);
        $template = $this->relationLoaded('website')
            ? app(WebsiteTemplateRegistry::class)->get($this->website->template_key)
            : null;

        $appearance = $this->appearance;
        if ($template?->presentationFallbackFor($this->type, $appearance['presentation'] ?? '') !== null) {
            $appearance = $template->normalizeSectionAppearance($this->type, $appearance);
        }

        return [
            'id' => $this->id,
            'type' => $this->type,
            'displayName' => $definition?->displayName ?? $this->type,
            'sortOrder' => $this->sort_order,
            'isEnabled' => $this->is_enabled,
            'content' => $this->normalizedContent,
            'appearance' => $appearance,
            'appearanceOptions' => $template?->appearanceOptionsFor($this->type),
            'mediaCapability' => $template?->mediaCapabilityFor($this->type),
            'itemMediaCapability' => $template?->itemMediaCapabilityFor($this->type),
            'presentationCapability' => $template?->presentationCapabilityFor($this->type),
        ];
    }
}
