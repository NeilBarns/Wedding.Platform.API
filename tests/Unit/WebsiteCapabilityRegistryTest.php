<?php

namespace Tests\Unit;

use App\Http\Resources\WebsiteTemplateCapabilitiesResource;
use App\Website\Capabilities\AppearanceControlCapability;
use App\Website\Capabilities\AppearanceControlScope;
use App\Website\Capabilities\AppearanceControlType;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\Elements\WebsiteElementType;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateRegistry;
use Tests\TestCase;

class WebsiteCapabilityRegistryTest extends TestCase
{
    public function test_every_production_template_emits_valid_deterministic_capabilities(): void
    {
        $templates = app(WebsiteTemplateRegistry::class);
        $resolver = app(WebsiteCapabilityResolver::class);
        $knownSections = array_keys(app(WebsiteSectionRegistry::class)->all());
        $knownElements = array_map(fn (WebsiteElementType $type): string => $type->value, WebsiteElementType::cases());
        $knownControls = [
            'headingAlignment', 'bodyAlignment', 'backgroundTreatment', 'emphasis', 'presentation',
            'mediaPlacement', 'mediaSize', 'frameStyle', 'cornerStyle', 'shadowStyle',
            'overlayStrength', 'foregroundColor', 'mediaSpacing', 'mediaContentGap',
        ];

        foreach ($templates->all() as $template) {
            $capabilities = $resolver->template($template);
            $this->assertNotNull($capabilities);
            $this->assertSame($template->supportedSectionTypes, array_map(fn ($section): string => $section->id, $capabilities->sections));
            $this->assertEqualsCanonicalizing(['narrativeBlock'], $capabilities->elements);

            foreach ($capabilities->sections as $section) {
                $this->assertContains($section->id, $knownSections);
                $sourcePresentations = $template->presentationCapabilityFor($section->id);
                $this->assertSame($sourcePresentations['default'] ?? null, $section->defaultPresentation);
                $this->assertSame(
                    array_column($sourcePresentations['options'] ?? [], 'key'),
                    array_map(fn ($presentation): string => $presentation->id, $section->presentations),
                );
                foreach ($section->allowedElementTypes ?? [] as $elementType) {
                    $this->assertContains($elementType, $knownElements);
                }
                $this->assertNull($section->compositionGroups);

                foreach ($section->appearanceControls as $control) {
                    $this->assertValidControl($control, $knownControls);
                }
                foreach ($section->presentations as $presentation) {
                    $this->assertNotSame('framed', $presentation->id);
                    foreach ($presentation->appearanceControls as $control) {
                        $this->assertValidControl($control, $knownControls);
                    }
                }
            }

            $first = (new WebsiteTemplateCapabilitiesResource($capabilities))->resolve(request());
            $second = (new WebsiteTemplateCapabilitiesResource($resolver->template($template)))->resolve(request());
            $this->assertSame(json_encode($first, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('video', json_encode($first, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('locationSummary', json_encode($first, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('logoMonogram', json_encode($first, JSON_THROW_ON_ERROR));
        }
    }

    public function test_story_is_the_only_element_authoring_section_and_allows_narrative_blocks_only(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);

        foreach (array_keys(app(WebsiteTemplateRegistry::class)->all()) as $templateKey) {
            $template = $resolver->template($templateKey);
            foreach ($template->sections as $section) {
                if ($section->id !== 'story') {
                    $this->assertNull($section->allowedElementTypes);
                    $this->assertNull($section->maximumElementCount);

                    continue;
                }

                $this->assertSame(['narrativeBlock'], $section->allowedElementTypes);
                $this->assertSame(20, $section->maximumElementCount);
                $this->assertTrue($resolver->allowsElement($templateKey, 'story', 'narrativeBlock'));
                $this->assertFalse($resolver->allowsElement($templateKey, 'story', 'compositionGroup'));
            }
        }
    }

    public function test_resolver_fails_safely_and_resolves_presentation_and_viewport_narrowing(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);
        $classic = WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1;

        $this->assertNull($resolver->template('unknown-template'));
        $this->assertNull($resolver->section($classic, 'unknown-section'));
        $this->assertNull($resolver->presentation($classic, 'hero', 'unknown-presentation'));
        $this->assertNull($resolver->controlsForViewport($classic, 'hero', 'classic', 'watch'));
        $this->assertFalse($resolver->allowsElement($classic, 'story', 'video'));

        $presentation = $resolver->presentation($classic, 'story');
        $this->assertSame('portraitStory', $presentation->id);
        $tablet = collect($resolver->controlsForViewport($classic, 'story', 'portraitStory', 'tablet'))->keyBy('id');
        $mobile = collect($resolver->controlsForViewport($classic, 'story', 'portraitStory', 'mobile'))->keyBy('id');
        $this->assertSame('top', $tablet['mediaPlacement']->default);
        $this->assertSame(['top', 'bottom', 'left', 'right'], array_column($tablet['mediaPlacement']->options, 'key'));
        $this->assertSame(['top', 'bottom'], array_column($mobile['mediaPlacement']->options, 'key'));
        $this->assertSame('balanced', $mobile['mediaSize']->default);
    }

    public function test_classic_and_modern_presentation_differences_are_preserved(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);
        $classicHero = $resolver->section(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'hero');
        $modernHero = $resolver->section(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, 'hero');
        $classicPeople = $resolver->section(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'people');
        $modernPeople = $resolver->section(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, 'people');

        $this->assertSame(['classic', 'immersive'], array_map(fn ($item): string => $item->id, $classicHero->presentations));
        $this->assertSame(['editorial', 'immersive'], array_map(fn ($item): string => $item->id, $modernHero->presentations));
        $this->assertSame(['medallions', 'portraitCards', 'namesOnly'], array_map(fn ($item): string => $item->id, $classicPeople->presentations));
        $this->assertSame(['editorialPortraits', 'squareGrid', 'minimal', 'namesOnly'], array_map(fn ($item): string => $item->id, $modernPeople->presentations));
    }

    /** @param list<string> $knownControls */
    private function assertValidControl(AppearanceControlCapability $control, array $knownControls): void
    {
        $this->assertContains($control->id, $knownControls);
        $this->assertContains($control->type, AppearanceControlType::cases());
        $this->assertContains($control->scope, AppearanceControlScope::cases());

        if ($control->type === AppearanceControlType::Number) {
            $this->assertNotNull($control->minimum);
            $this->assertNotNull($control->maximum);
            $this->assertNotNull($control->step);
            $this->assertGreaterThanOrEqual($control->minimum, $control->default);
            $this->assertLessThanOrEqual($control->maximum, $control->default);
            $this->assertGreaterThan(0, $control->step);

            return;
        }

        $allowed = array_column($control->options, 'key');
        $this->assertNotEmpty($allowed);
        if ($control->type === AppearanceControlType::Option) {
            $this->assertContains($control->default, $allowed);
        } else {
            foreach ($control->default as $value) {
                $this->assertContains($value, $allowed);
            }
        }
        foreach ($control->viewports as $viewport) {
            $viewportAllowed = array_column($viewport->options, 'key');
            $this->assertNotEmpty($viewportAllowed);
            foreach (is_array($viewport->default) ? $viewport->default : [$viewport->default] as $value) {
                $this->assertContains($value, $viewportAllowed);
            }
        }
    }
}
