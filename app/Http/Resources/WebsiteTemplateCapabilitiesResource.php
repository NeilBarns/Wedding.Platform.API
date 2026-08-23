<?php

namespace App\Http\Resources;

use App\Website\Capabilities\AppearanceControlCapability;
use App\Website\Capabilities\AppearanceControlType;
use App\Website\Capabilities\PresentationCapability;
use App\Website\Capabilities\SectionCapability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteTemplateCapabilitiesResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'elements' => $this->elements,
            'sections' => array_map(fn (SectionCapability $section): array => [
                'id' => $section->id,
                'appearanceControls' => array_map($this->serializeControl(...), $section->appearanceControls),
                'defaultPresentation' => $section->defaultPresentation,
                'presentations' => array_map(fn (PresentationCapability $presentation): array => [
                    'id' => $presentation->id,
                    'displayName' => $presentation->displayName,
                    'description' => $presentation->description,
                    'preview' => $presentation->preview,
                    'appearanceControls' => array_map($this->serializeControl(...), $presentation->appearanceControls),
                ], $section->presentations),
                'elements' => $section->allowedElementTypes === null ? null : [
                    'allowedTypes' => $section->allowedElementTypes,
                    'maxCount' => $section->maximumElementCount,
                    'compositionGroups' => $section->compositionGroups,
                ],
            ], $this->sections),
        ];
    }

    private function serializeControl(AppearanceControlCapability $control): array
    {
        $serialized = [
            'id' => $control->id,
            'type' => $control->type->value,
            'scope' => $control->scope->value,
            'default' => $control->default,
        ];

        if ($control->type !== AppearanceControlType::Number) {
            $serialized['options'] = $control->options;
        } else {
            $serialized['minimum'] = $control->minimum;
            $serialized['maximum'] = $control->maximum;
            $serialized['step'] = $control->step;
        }

        if ($control->viewports !== []) {
            $serialized['viewports'] = array_map(fn ($viewport): array => [
                'default' => $viewport->default,
                'options' => $viewport->options,
            ], $control->viewports);
        }

        return $serialized;
    }
}
