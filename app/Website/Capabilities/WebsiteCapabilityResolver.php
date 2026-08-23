<?php

namespace App\Website\Capabilities;

use App\Website\Elements\WebsiteElementType;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateDefinition;
use App\Website\WebsiteTemplateRegistry;

final class WebsiteCapabilityResolver
{
    /** @var array<string, TemplateCapabilities> */
    private array $resolvedTemplates = [];

    public function __construct(
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteSectionRegistry $sections,
    ) {}

    public function template(string|WebsiteTemplateDefinition $template): ?TemplateCapabilities
    {
        if (is_string($template) && isset($this->resolvedTemplates[$template])) {
            return $this->resolvedTemplates[$template];
        }

        $requestedKey = is_string($template) ? $template : null;
        $definition = is_string($template) ? $this->templates->get($template) : $template;
        if ($definition === null) {
            return null;
        }

        $sections = [];
        foreach ($definition->supportedSectionTypes as $sectionId) {
            if ($this->sections->get($sectionId) !== null) {
                $sections[] = $this->sectionFromDefinition($definition, $sectionId);
            }
        }

        $elements = collect($sections)->flatMap(fn (SectionCapability $section): array => $section->allowedElementTypes ?? [])
            ->unique()->values()->all();

        $capabilities = new TemplateCapabilities(elements: $elements, sections: $sections);
        if ($requestedKey !== null) {
            $this->resolvedTemplates[$requestedKey] = $capabilities;
        }

        return $capabilities;
    }

    public function section(string|WebsiteTemplateDefinition $template, string $sectionId): ?SectionCapability
    {
        $capabilities = $this->template($template);

        return collect($capabilities?->sections)->first(fn (SectionCapability $section): bool => $section->id === $sectionId);
    }

    public function presentation(string|WebsiteTemplateDefinition $template, string $sectionId, ?string $presentationId = null): ?PresentationCapability
    {
        $section = $this->section($template, $sectionId);
        $presentationId ??= $section?->defaultPresentation;

        return collect($section?->presentations)->first(fn (PresentationCapability $presentation): bool => $presentation->id === $presentationId);
    }

    /** @return list<AppearanceControlCapability>|null */
    public function controlsForViewport(string|WebsiteTemplateDefinition $template, string $sectionId, ?string $presentationId, string $viewport): ?array
    {
        $section = $this->section($template, $sectionId);
        if ($section === null || ! in_array($viewport, ['desktop', ...WebsiteSectionAppearance::RESPONSIVE_VIEWPORTS], true)) {
            return null;
        }

        $presentation = $this->presentation($template, $sectionId, $presentationId);
        if ($presentationId !== null && $presentation === null) {
            return null;
        }

        return array_map(
            fn (AppearanceControlCapability $control): AppearanceControlCapability => $viewport === 'desktop' ? $control : $control->forViewport($viewport),
            [...$section->appearanceControls, ...($presentation?->appearanceControls ?? [])],
        );
    }

    public function allowsElement(string|WebsiteTemplateDefinition $template, string $sectionId, string $elementType): bool
    {
        if (WebsiteElementType::tryFrom($elementType) === null) {
            return false;
        }

        return in_array($elementType, $this->section($template, $sectionId)?->allowedElementTypes ?? [], true);
    }

    private function sectionFromDefinition(WebsiteTemplateDefinition $template, string $sectionId): SectionCapability
    {
        $appearanceOptions = $template->appearanceOptionsFor($sectionId) ?? [];
        $appearanceDefaults = $template->appearanceDefaultsFor($sectionId) ?? [];
        $controls = [];
        foreach ([
            'headingAlignment' => 'headingAlignments',
            'bodyAlignment' => 'bodyAlignments',
            'backgroundTreatment' => 'backgroundTreatments',
            'emphasis' => 'emphasisOptions',
        ] as $id => $group) {
            if (isset($appearanceOptions[$group], $appearanceDefaults[$id])) {
                $controls[] = $this->optionControl(
                    $id,
                    in_array($id, WebsiteSectionAppearance::RESPONSIVE_SETTINGS, true) ? AppearanceControlScope::Responsive : AppearanceControlScope::Shared,
                    $appearanceDefaults[$id],
                    $appearanceOptions[$group],
                    $template,
                    $sectionId,
                );
            }
        }

        $presentationDefinition = $template->presentationCapabilityFor($sectionId);
        $presentations = [];
        if ($presentationDefinition !== null) {
            $controls[] = $this->optionControl(
                'presentation',
                AppearanceControlScope::Shared,
                $presentationDefinition['default'],
                array_map(fn (array $option): array => [
                    'key' => $option['key'],
                    'displayName' => $option['displayName'],
                ], $presentationDefinition['options']),
            );
            foreach ($presentationDefinition['options'] as $option) {
                $presentations[] = new PresentationCapability(
                    id: $option['key'],
                    displayName: $option['displayName'],
                    description: $option['description'],
                    preview: $option['preview'],
                    appearanceControls: $this->presentationControls($template, $sectionId, $option['key']),
                );
            }
        }

        $allowedElements = $sectionId === 'story' ? [WebsiteElementType::NarrativeBlock->value] : null;

        return new SectionCapability(
            id: $sectionId,
            appearanceControls: $controls,
            defaultPresentation: $presentationDefinition['default'] ?? null,
            presentations: $presentations,
            allowedElementTypes: $allowedElements,
            maximumElementCount: $allowedElements === null ? null : 20,
        );
    }

    /** @return list<AppearanceControlCapability> */
    private function presentationControls(WebsiteTemplateDefinition $template, string $sectionId, string $presentationId): array
    {
        $mediaControls = $template->mediaControlsFor($sectionId, $presentationId);
        $controls = [];
        foreach ($template->mediaControlSettings() as $id => $group) {
            if (! isset($mediaControls[$group])) {
                continue;
            }
            $controls[] = $this->optionControl(
                $id,
                in_array($id, WebsiteSectionAppearance::RESPONSIVE_SETTINGS, true) ? AppearanceControlScope::Responsive : AppearanceControlScope::Shared,
                $mediaControls[$group]['default'],
                $mediaControls[$group]['options'],
                $template,
                $sectionId,
                $presentationId,
            );
        }
        if (isset($mediaControls['mediaSpacing'])) {
            $controls[] = new AppearanceControlCapability(
                id: 'mediaSpacing',
                type: AppearanceControlType::Spacing,
                scope: AppearanceControlScope::Responsive,
                default: $mediaControls['mediaSpacing']['default'],
                options: $mediaControls['mediaSpacing']['options'],
                viewports: $this->viewportCapabilities($template, $sectionId, $presentationId, 'mediaSpacing', $mediaControls['mediaSpacing']['options']),
            );
        }
        if (isset($mediaControls['overlayStrength'])) {
            $control = $mediaControls['overlayStrength'];
            $controls[] = new AppearanceControlCapability(
                id: 'overlayStrength',
                type: AppearanceControlType::Number,
                scope: AppearanceControlScope::Shared,
                default: (float) $control['default'],
                minimum: (float) $control['min'],
                maximum: (float) $control['max'],
                step: (float) $control['step'],
            );
        }

        return $controls;
    }

    /** @param list<array{key: string, displayName: string}> $options */
    private function optionControl(
        string $id,
        AppearanceControlScope $scope,
        string $default,
        array $options,
        ?WebsiteTemplateDefinition $template = null,
        ?string $sectionId = null,
        ?string $presentationId = null,
    ): AppearanceControlCapability {
        return new AppearanceControlCapability(
            id: $id,
            type: AppearanceControlType::Option,
            scope: $scope,
            default: $default,
            options: $options,
            viewports: $scope === AppearanceControlScope::Responsive && $template !== null && $sectionId !== null
                ? $this->viewportCapabilities($template, $sectionId, $presentationId, $id, $options)
                : [],
        );
    }

    /**
     * @param  list<array{key: string, displayName: string}>  $fallbackOptions
     * @return array<string, ViewportControlCapability>
     */
    private function viewportCapabilities(
        WebsiteTemplateDefinition $template,
        string $sectionId,
        ?string $presentationId,
        string $setting,
        array $fallbackOptions,
    ): array {
        $viewports = [];
        foreach (WebsiteSectionAppearance::RESPONSIVE_VIEWPORTS as $viewport) {
            $control = $template->responsiveControlFor($sectionId, $presentationId, $viewport, $setting);
            $default = $template->responsiveDefaultFor($sectionId, $presentationId, $viewport, $setting);
            if ($default !== null) {
                $viewports[$viewport] = new ViewportControlCapability(
                    default: $default,
                    options: $control['options'] ?? $fallbackOptions,
                );
            }
        }

        return $viewports;
    }
}
