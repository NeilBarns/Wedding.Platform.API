<?php

namespace Tests\Unit;

use App\Http\Resources\WebsiteTemplateCapabilitiesResource;
use App\Website\Capabilities\AppearanceControlCapability;
use App\Website\Capabilities\AppearanceControlScope;
use App\Website\Capabilities\AppearanceControlType;
use App\Website\Capabilities\GlobalDesignControlId;
use App\Website\Capabilities\GlobalDesignControlType;
use App\Website\Capabilities\TypographyRole;
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

    public function test_global_design_capabilities_preserve_registry_options_defaults_and_resolver_lookups(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);
        $expectedControls = [
            GlobalDesignControlId::ColorTheme->value => ['colorThemes', GlobalDesignControlType::PalettePreset],
            GlobalDesignControlId::FontSet->value => ['fontSets', GlobalDesignControlType::TypographyPairing],
            GlobalDesignControlId::ArtStyle->value => ['artStyles', GlobalDesignControlType::ArtStyle],
        ];

        foreach (app(WebsiteTemplateRegistry::class)->all() as $template) {
            $capability = $resolver->globalDesign($template);
            $this->assertNotNull($capability);
            $this->assertSame(array_keys($expectedControls), array_map(
                fn ($control): string => $control->id->value,
                $capability->controls,
            ));

            foreach ($capability->controls as $control) {
                [$group, $type] = $expectedControls[$control->id->value];
                $this->assertSame($type, $control->type);
                $this->assertSame($template->designOptions[$group], $control->options);
                $this->assertSame($template->defaultDesignSettings[$control->id->value], $control->default);
                $this->assertContains($control->default, array_column($control->options, 'key'));
                $this->assertEquals($control, $resolver->globalDesignControl($template, $control->id));
                $this->assertSame($control->default, $resolver->globalDesignDefault($template, $control->id));
                $this->assertTrue($resolver->allowsGlobalDesignValue($template, $control->id, $control->default));
                $this->assertFalse($resolver->allowsGlobalDesignValue($template, $control->id, 'unsupported-value'));
            }

            $serialized = (new WebsiteTemplateCapabilitiesResource($resolver->template($template)))->resolve(request());
            $this->assertSame(array_keys($expectedControls), array_column($serialized['globalDesign']['controls'], 'id'));
            $this->assertSame(
                $resolver->globalDesignDefaults($template),
                $resolver->normalizeGlobalDesignSettings($template, [
                    'colorTheme' => 'unsupported-value',
                    'fontSet' => null,
                ]),
            );
        }

        $classic = $resolver->globalDesign(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $modern = $resolver->globalDesign(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $this->assertSame(['terracotta', 'editorial', 'minimal'], array_column($classic->controls, 'default'));
        $this->assertSame(['ink', 'editorial', 'clean'], array_column($modern->controls, 'default'));
        foreach ([0, 1, 2] as $controlIndex) {
            $this->assertNotSame($classic->controls[$controlIndex]->options, $modern->controls[$controlIndex]->options);
        }
        $this->assertNull($resolver->globalDesign('unknown-template'));
        $this->assertNull($resolver->globalDesignDefaults('unknown-template'));
        $this->assertNull($resolver->normalizeGlobalDesignSettings('unknown-template', []));
        $this->assertNull($resolver->globalDesignControl(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'unknown-control'));
        $this->assertNull($resolver->globalDesignDefault(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'unknown-control'));
        $this->assertFalse($resolver->allowsGlobalDesignValue(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'unknown-control', 'terracotta'));
    }

    public function test_template_design_libraries_preserve_current_palette_and_typography_contracts(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);
        $expected = [
            WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1 => [
                'defaults' => ['colorTheme' => 'terracotta', 'fontSet' => 'editorial', 'artStyle' => 'minimal'],
                'palettes' => [
                    'terracotta' => ['Terracotta', ['canvas' => '#f8f0e4', 'surface' => '#f1e5d5', 'text' => '#3b312d', 'textMuted' => '#6c5f57', 'accent' => '#9d5b45', 'accentContrast' => '#ffffff', 'border' => '#806d5e', 'ornament' => '#78805f']],
                    'olive' => ['Olive', ['canvas' => '#f4f1e5', 'surface' => '#e8e4d2', 'text' => '#34372c', 'textMuted' => '#626454', 'accent' => '#70764e', 'accentContrast' => '#ffffff', 'border' => '#74745f', 'ornament' => '#a06a4f']],
                    'sage' => ['Sage', ['canvas' => '#f1f3e9', 'surface' => '#e2e8d9', 'text' => '#303a33', 'textMuted' => '#5f6c63', 'accent' => '#748a70', 'accentContrast' => '#ffffff', 'border' => '#718075', 'ornament' => '#a86650']],
                    'burgundy' => ['Burgundy', ['canvas' => '#f7eeea', 'surface' => '#ecddda', 'text' => '#3d292d', 'textMuted' => '#715d61', 'accent' => '#7d3443', 'accentContrast' => '#ffffff', 'border' => '#82696d', 'ornament' => '#7b7955']],
                    'neutral' => ['Warm Neutral', ['canvas' => '#f5f1eb', 'surface' => '#e9e3dc', 'text' => '#35312e', 'textMuted' => '#69625d', 'accent' => '#74645a', 'accentContrast' => '#ffffff', 'border' => '#7c746e', 'ornament' => '#777363']],
                ],
                'typography' => [
                    'editorial' => ['Editorial', 'editorial-serif', 'modern-sans'],
                    'romantic' => ['Romantic', 'romantic-serif', 'classic-serif'],
                    'modern' => ['Modern', 'modern-sans', 'modern-sans'],
                ],
            ],
            WebsiteTemplateRegistry::MODERN_EDITORIAL_V1 => [
                'defaults' => ['colorTheme' => 'ink', 'fontSet' => 'editorial', 'artStyle' => 'clean'],
                'palettes' => [
                    'ink' => ['Ink', ['canvas' => '#f5f3ee', 'surface' => '#e9e6df', 'text' => '#171717', 'textMuted' => '#65615b', 'accent' => '#171717', 'accentContrast' => '#ffffff', 'border' => '#908a80']],
                    'stone' => ['Stone', ['canvas' => '#f3f1ed', 'surface' => '#e4e0da', 'text' => '#302e2a', 'textMuted' => '#716c64', 'accent' => '#686158', 'accentContrast' => '#ffffff', 'border' => '#999188']],
                    'blush' => ['Blush', ['canvas' => '#faf3f1', 'surface' => '#f0dfdc', 'text' => '#3b292b', 'textMuted' => '#765f61', 'accent' => '#9c5f64', 'accentContrast' => '#ffffff', 'border' => '#b99191']],
                    'plum' => ['Plum', ['canvas' => '#f7f2f6', 'surface' => '#e9dde7', 'text' => '#302330', 'textMuted' => '#6e5a6c', 'accent' => '#5f405f', 'accentContrast' => '#ffffff', 'border' => '#927b8f']],
                    'navy' => ['Navy', ['canvas' => '#f1f4f7', 'surface' => '#dde4eb', 'text' => '#182432', 'textMuted' => '#596775', 'accent' => '#263c5a', 'accentContrast' => '#ffffff', 'border' => '#77889b']],
                ],
                'typography' => [
                    'editorial' => ['Editorial', 'editorial-serif', 'modern-sans'],
                    'fashion' => ['Fashion', 'fashion-serif', 'fashion-sans'],
                    'minimal' => ['Minimal', 'modern-sans', 'modern-sans'],
                ],
            ],
        ];

        foreach ($expected as $templateKey => $contract) {
            $template = app(WebsiteTemplateRegistry::class)->get($templateKey);
            $library = $resolver->template($template)->designLibrary;
            $colors = collect($library->colors)->keyBy('id');
            $this->assertCount($colors->count(), $colors->pluck('id')->unique());
            $this->assertCount(count($library->fontFamilies), collect($library->fontFamilies)->pluck('id')->unique());

            foreach ($contract['palettes'] as $presetId => [$label, $roles]) {
                $preset = collect($library->palettePresets)->firstWhere('id', $presetId);
                $this->assertSame($label, $preset->displayName);
                $this->assertSame($roles, collect($preset->roles)->map(fn (string $colorId): string => $colors[$colorId]->value)->all());
            }
            foreach ($contract['typography'] as $presetId => [$label, $headingId, $bodyId]) {
                $preset = collect($library->typographyPresets)->firstWhere('id', $presetId);
                $families = collect($library->fontFamilies)->keyBy('id');
                $this->assertSame([$label, $headingId, $bodyId], [$preset->displayName, $preset->headingFontId, $preset->bodyFontId]);
                $this->assertContains(TypographyRole::Heading, $families[$headingId]->allowedRoles);
                $this->assertContains(TypographyRole::Body, $families[$bodyId]->allowedRoles);
            }

            $this->assertSame(array_keys($contract['palettes']), array_column($template->designOptions['colorThemes'], 'key'));
            $this->assertSame(array_column($contract['palettes'], 0), array_column($template->designOptions['colorThemes'], 'displayName'));
            $this->assertSame(array_keys($contract['typography']), array_column($template->designOptions['fontSets'], 'key'));
            $this->assertSame(array_column($contract['typography'], 0), array_column($template->designOptions['fontSets'], 'displayName'));
            $this->assertSame($contract['defaults'], $template->defaultDesignSettings);
        }

        $classic = $resolver->template(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)->designLibrary;
        $modern = $resolver->template(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1)->designLibrary;
        $this->assertTrue(collect($classic->palettePresets)->every(fn ($preset): bool => isset($preset->roles['ornament'])));
        $this->assertTrue(collect($modern->palettePresets)->every(fn ($preset): bool => ! isset($preset->roles['ornament'])));
    }

    public function test_every_responsive_control_serializes_fully_resolved_viewport_defaults_in_resolver_parity(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);
        $seenResponsiveControls = [];

        foreach (app(WebsiteTemplateRegistry::class)->all() as $template) {
            $capabilities = $resolver->template($template);
            $serialized = (new WebsiteTemplateCapabilitiesResource($capabilities))->resolve(request());

            foreach ($capabilities->sections as $sectionIndex => $section) {
                $contexts = [[null, $section->appearanceControls, $serialized['sections'][$sectionIndex]['appearanceControls']]];
                foreach ($section->presentations as $presentationIndex => $presentation) {
                    $contexts[] = [
                        $presentation->id,
                        $presentation->appearanceControls,
                        $serialized['sections'][$sectionIndex]['presentations'][$presentationIndex]['appearanceControls'],
                    ];
                }

                foreach ($contexts as [$presentationId, $controls, $serializedControls]) {
                    foreach ($controls as $controlIndex => $control) {
                        if ($control->scope !== AppearanceControlScope::Responsive) {
                            continue;
                        }

                        $seenResponsiveControls[$control->id] = true;
                        foreach (['tablet', 'mobile'] as $viewport) {
                            $this->assertArrayHasKey($viewport, $control->viewports);
                            $resolved = collect($resolver->controlsForViewport(
                                $template,
                                $section->id,
                                $presentationId,
                                $viewport,
                            ))->keyBy('id')->get($control->id);
                            $this->assertNotNull($resolved);
                            $this->assertSame($control->viewports[$viewport]->default, $resolved->default);
                            $this->assertSame($control->viewports[$viewport]->options, $resolved->options);
                            $this->assertSame($resolved->default, $serializedControls[$controlIndex]['viewports'][$viewport]['default']);
                            $this->assertSame($resolved->options, $serializedControls[$controlIndex]['viewports'][$viewport]['options']);
                            $this->assertResolvedDefaultIsAllowed($resolved);
                        }
                    }
                }
            }
        }

        $this->assertEqualsCanonicalizing(
            ['headingAlignment', 'bodyAlignment', 'mediaPlacement', 'mediaSize', 'mediaSpacing', 'mediaContentGap'],
            array_keys($seenResponsiveControls),
        );
    }

    public function test_responsive_control_without_a_resolved_viewport_is_unsupported(): void
    {
        $control = new AppearanceControlCapability(
            id: 'mediaSize',
            type: AppearanceControlType::Option,
            scope: AppearanceControlScope::Responsive,
            default: 'balanced',
            options: [['key' => 'balanced', 'displayName' => 'Balanced']],
        );

        $this->assertNull($control->forViewport('tablet'));
        $this->assertNull($control->forViewport('mobile'));
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

    private function assertResolvedDefaultIsAllowed(AppearanceControlCapability $control): void
    {
        $allowed = array_column($control->options, 'key');
        foreach (is_array($control->default) ? $control->default : [$control->default] as $value) {
            $this->assertContains($value, $allowed);
        }
    }
}
