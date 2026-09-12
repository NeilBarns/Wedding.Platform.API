<?php

namespace App\Http\Resources;

use App\Website\Capabilities\ContextDefaultsIntent;
use App\Website\Capabilities\DesignContextResolver;
use App\Website\Capabilities\ResolvedDesignContext;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\Elements\DividerJsonShape;
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
        $designDefaults = is_array($appearance['designDefaults'] ?? null) ? $appearance['designDefaults'] : [];
        unset($appearance['designDefaults']);
        if ($template?->presentationFallbackFor($this->type, $appearance['presentation'] ?? '') !== null) {
            $appearance = $template->normalizeSectionAppearance($this->type, $appearance);
        }
        if ($this->type === 'blank') {
            $appearance = array_intersect_key($appearance, array_flip(['backgroundTreatment', 'decorativeAppearance', 'innerSpacing', 'responsive']));
            $decorativeAppearance = $appearance['decorativeAppearance'] ?? null;
            $innerSpacing = $appearance['innerSpacing'] ?? null;
            $responsive = $appearance['responsive'] ?? null;
            $appearance = [
                'headingAlignment' => 'inherit',
                'bodyAlignment' => 'inherit',
                'backgroundTreatment' => in_array($appearance['backgroundTreatment'] ?? null, ['inherit', 'custom'], true) ? $appearance['backgroundTreatment'] : 'inherit',
                'emphasis' => 'inherit',
            ];
            if (is_array($decorativeAppearance)) {
                $appearance['decorativeAppearance'] = $decorativeAppearance;
            }
            if (is_array($innerSpacing) && $innerSpacing !== []) {
                $appearance['innerSpacing'] = $innerSpacing;
            }
            if (is_array($responsive) && $responsive !== []) {
                $appearance['responsive'] = $responsive;
            }
            $designDefaults = [];
        }

        $resolvedContext = null;
        if ($template !== null && $this->relationLoaded('website')) {
            $capabilities = app(WebsiteCapabilityResolver::class);
            $projectDefaults = $capabilities->resolveProjectDesignDefaults($template, $this->website->design_settings);
            $sectionCapability = $capabilities->section($template, $this->type);
            if ($projectDefaults !== null && $sectionCapability !== null) {
                $presentationId = is_string($appearance['presentation'] ?? null)
                    ? $appearance['presentation']
                    : $sectionCapability->defaultPresentation;
                $presentation = $presentationId === null ? null : $capabilities->presentation($template, $this->type, $presentationId);
                $resolved = app(DesignContextResolver::class)->resolveSection(
                    ResolvedDesignContext::fromProjectDefaults($projectDefaults),
                    $sectionCapability,
                    ContextDefaultsIntent::fromArray($designDefaults),
                    $presentation,
                );
                $resolvedContext = get_object_vars($resolved);
            }
        }

        return [
            'id' => $this->id,
            'type' => $this->type,
            'displayName' => $definition?->displayName ?? $this->type,
            'editorName' => $definition?->lifecycle->isUserOwned() === true ? $this->editor_name : null,
            'sortOrder' => $this->sort_order,
            'isEnabled' => $this->is_enabled,
            'content' => $this->serializedContent(),
            'appearance' => $appearance,
            'designDefaults' => (object) $designDefaults,
            'resolvedDesignContext' => $resolvedContext,
            'appearanceOptions' => $template?->appearanceOptionsFor($this->type),
            'mediaCapability' => $template?->mediaCapabilityFor($this->type),
            'itemMediaCapability' => $template?->itemMediaCapabilityFor($this->type),
            'presentationCapability' => $template?->presentationCapabilityFor($this->type),
        ];
    }

    /** @return array<string, mixed> */
    private function serializedContent(): array
    {
        $content = $this->normalizedContent;

        if (isset($content['childFlow']['elements'])) {
            $content['childFlow']['elements'] = DividerJsonShape::serializeElements($content['childFlow']['elements']);
        }

        return $content;
    }
}
