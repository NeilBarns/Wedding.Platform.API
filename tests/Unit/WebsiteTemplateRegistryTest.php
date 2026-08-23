<?php

namespace Tests\Unit;

use App\Enums\EventType;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateDefinition;
use App\Website\WebsiteTemplateRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;

class WebsiteTemplateRegistryTest extends TestCase
{
    public function test_registry_exposes_both_production_wedding_templates(): void
    {
        $registry = new WebsiteTemplateRegistry;
        $definitions = $registry->all();
        $template = $registry->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);

        $this->assertSame([
            WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
            WebsiteTemplateRegistry::MODERN_EDITORIAL_V1,
        ], array_keys($definitions));
        $this->assertSame('Classic Filipiniana', $template->displayName);
        $this->assertSame([EventType::Wedding], $template->supportedEventTypes);
        $this->assertSame(array_keys((new WebsiteSectionRegistry)->all()), $template->supportedSectionTypes);
        $this->assertSame(array_keys($definitions), array_keys($registry->forEventType(EventType::Wedding)));
        $this->assertSame($template->key, $registry->defaultForEventType(EventType::Wedding)->key);
        $registry->assertValid();
    }

    public function test_classic_hero_supports_four_placements_and_normalization_preserves_them(): void
    {
        $template = (new WebsiteTemplateRegistry)->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $controls = $template->mediaControlsFor('hero', 'classic');

        $this->assertSame(
            ['top', 'right', 'bottom', 'left'],
            array_column($controls['mediaPlacements']['options'], 'key'),
        );

        foreach (['top', 'right', 'bottom', 'left'] as $placement) {
            $normalized = $template->normalizeSectionAppearance('hero', [
                ...WebsiteSectionAppearance::DEFAULT,
                'presentation' => 'classic',
                'mediaPlacement' => $placement,
            ]);

            $this->assertSame($placement, $normalized['mediaPlacement']);
        }
    }

    public function test_effective_viewport_appearance_uses_independent_template_defaults_then_sparse_override(): void
    {
        $template = (new WebsiteTemplateRegistry)->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $appearance = [
            ...WebsiteSectionAppearance::DEFAULT,
            'presentation' => 'classic',
            'mediaPlacement' => 'left',
            'headingAlignment' => 'left',
            'backgroundTreatment' => 'accent',
            'emphasis' => 'featured',
            'mediaSize' => 'feature',
            'mediaSpacing' => array_fill_keys(['top', 'right', 'bottom', 'left'], 'large'),
            'responsive' => [
                'tablet' => ['headingAlignment' => 'right'],
                'mobile' => ['mediaPlacement' => 'bottom', 'mediaSize' => 'feature'],
            ],
        ];

        $desktop = $template->resolveSectionAppearanceForViewport('hero', $appearance, 'desktop');
        $tablet = $template->resolveSectionAppearanceForViewport('hero', $appearance, 'tablet');
        $mobile = $template->resolveSectionAppearanceForViewport('hero', $appearance, 'mobile');

        $this->assertSame('left', $desktop['mediaPlacement']);
        $this->assertArrayNotHasKey('responsive', $desktop);
        $this->assertSame('top', $tablet['mediaPlacement']);
        $this->assertSame('right', $tablet['headingAlignment']);
        $this->assertSame('accent', $tablet['backgroundTreatment']);
        $this->assertSame('featured', $tablet['emphasis']);
        $this->assertSame('balanced', $tablet['mediaSize']);
        $this->assertSame(array_fill_keys(['top', 'right', 'bottom', 'left'], 'medium'), $tablet['mediaSpacing']);
        $this->assertSame('bottom', $mobile['mediaPlacement']);
        $this->assertSame('feature', $mobile['mediaSize']);
        $this->assertSame('inherit', $mobile['headingAlignment']);
        $this->assertSame('accent', $mobile['backgroundTreatment']);
        $this->assertSame('featured', $mobile['emphasis']);
        $this->assertSame(array_fill_keys(['top', 'right', 'bottom', 'left'], 'medium'), $mobile['mediaSpacing']);
    }

    public function test_viewport_resolution_uses_viewport_default_then_explicit_override_without_desktop_fallback(): void
    {
        $template = (new WebsiteTemplateRegistry)->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $capabilities = $template->sectionPresentationCapabilities;
        foreach ($capabilities['hero']['options'] as &$option) {
            if ($option['key'] === 'classic') {
                $option['mediaControls']['responsive']['mobile']['mediaPlacement'] = [
                    'default' => 'bottom',
                    'options' => [
                        ['key' => 'top', 'displayName' => 'Top'],
                        ['key' => 'bottom', 'displayName' => 'Bottom'],
                    ],
                ];
            }
        }
        unset($option);
        $fixture = new WebsiteTemplateDefinition(
            key: $template->key,
            displayName: $template->displayName,
            description: $template->description,
            styleTags: $template->styleTags,
            enabled: $template->enabled,
            supportedEventTypes: $template->supportedEventTypes,
            supportedSectionTypes: $template->supportedSectionTypes,
            designOptions: $template->designOptions,
            defaultDesignSettings: $template->defaultDesignSettings,
            sectionAppearanceOptions: $template->sectionAppearanceOptions,
            sectionAppearanceDefaults: $template->sectionAppearanceDefaults,
            sectionMediaCapabilities: $template->sectionMediaCapabilities,
            sectionItemMediaCapabilities: $template->sectionItemMediaCapabilities,
            sectionPresentationCapabilities: $capabilities,
            sectionPresentationFallbacks: $template->sectionPresentationFallbacks,
        );

        $base = [
            ...WebsiteSectionAppearance::DEFAULT,
            'presentation' => 'classic',
            'mediaPlacement' => 'right',
            'mediaSize' => 'feature',
        ];
        $desktop = $fixture->resolveSectionAppearanceForViewport('hero', $base, 'desktop');
        $mobile = $fixture->resolveSectionAppearanceForViewport('hero', $base, 'mobile');
        $mobileOverride = $fixture->resolveSectionAppearanceForViewport('hero', [
            ...$base,
            'responsive' => ['mobile' => ['mediaPlacement' => 'top']],
        ], 'mobile');
        $normalized = $fixture->normalizeSectionAppearance('hero', [
            ...$base,
            'responsive' => ['mobile' => ['mediaPlacement' => 'left']],
        ]);

        $this->assertSame('right', $desktop['mediaPlacement']);
        $this->assertSame('bottom', $mobile['mediaPlacement']);
        $this->assertSame('balanced', $mobile['mediaSize']);
        $this->assertSame('top', $mobileOverride['mediaPlacement']);
        $this->assertArrayNotHasKey('responsive', $normalized);
    }

    public function test_contained_media_presentations_expose_deliberate_tablet_and_mobile_placement_defaults(): void
    {
        $registry = new WebsiteTemplateRegistry;
        $cases = [
            [WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'hero', 'classic', 'top'],
            [WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'story', 'textFirst', 'bottom'],
            [WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'story', 'portraitStory', 'top'],
            [WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'venue', 'detailsFirst', 'top'],
            [WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, 'hero', 'editorial', 'top'],
            [WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, 'story', 'textFirst', 'bottom'],
            [WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, 'story', 'editorial', 'top'],
            [WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, 'venue', 'detailsFirst', 'top'],
        ];

        foreach ($cases as [$templateKey, $sectionType, $presentation, $default]) {
            foreach (['tablet', 'mobile'] as $viewport) {
                $control = $registry->get($templateKey)->responsiveControlFor($sectionType, $presentation, $viewport, 'mediaPlacement');
                $this->assertSame($default, $control['default']);
                $classicTabletHorizontal = $templateKey === WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1
                    && (($sectionType === 'story' && $presentation === 'portraitStory') || ($sectionType === 'venue' && $presentation === 'detailsFirst'));
                $expectedOptions = $viewport === 'tablet' && $classicTabletHorizontal
                    ? ['top', 'bottom', 'left', 'right']
                    : ['top', 'bottom'];
                $this->assertSame($expectedOptions, array_column($control['options'], 'key'));
            }
        }

        $registry->assertValid();
    }

    public function test_tablet_placement_normalization_drops_historical_horizontal_overrides(): void
    {
        $template = (new WebsiteTemplateRegistry)->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $base = [
            ...WebsiteSectionAppearance::DEFAULT,
            'presentation' => 'classic',
            'mediaPlacement' => 'right',
        ];

        foreach (['left', 'right'] as $historicalPlacement) {
            $normalized = $template->normalizeSectionAppearance('hero', [
                ...$base,
                'responsive' => ['tablet' => ['mediaPlacement' => $historicalPlacement]],
            ]);

            $this->assertArrayNotHasKey('responsive', $normalized);
            $this->assertSame('top', $template->resolveSectionAppearanceForViewport('hero', $normalized, 'tablet')['mediaPlacement']);
        }

        foreach (['top', 'bottom'] as $placement) {
            $normalized = $template->normalizeSectionAppearance('hero', [
                ...$base,
                'responsive' => ['tablet' => ['mediaPlacement' => $placement]],
            ]);

            $this->assertSame($placement, $normalized['responsive']['tablet']['mediaPlacement']);
            $this->assertSame($placement, $template->resolveSectionAppearanceForViewport('hero', $normalized, 'tablet')['mediaPlacement']);
        }

        $this->assertSame('right', $template->resolveSectionAppearanceForViewport('hero', $base, 'desktop')['mediaPlacement']);
        $this->assertSame('top', $template->resolveSectionAppearanceForViewport('hero', $base, 'tablet')['mediaPlacement']);
    }

    public function test_classic_portrait_story_tablet_supports_all_semantic_placements(): void
    {
        $template = (new WebsiteTemplateRegistry)->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $control = $template->responsiveControlFor('story', 'portraitStory', 'tablet', 'mediaPlacement');

        $this->assertSame('top', $control['default']);
        $this->assertSame(['top', 'bottom', 'left', 'right'], array_column($control['options'], 'key'));

        foreach (['top', 'bottom', 'left', 'right'] as $placement) {
            $normalized = $template->normalizeSectionAppearance('story', [
                ...WebsiteSectionAppearance::DEFAULT,
                'presentation' => 'portraitStory',
                'mediaPlacement' => 'left',
                'responsive' => ['tablet' => ['mediaPlacement' => $placement]],
            ]);

            $this->assertSame($placement, $normalized['responsive']['tablet']['mediaPlacement']);
            $this->assertSame($placement, $template->resolveSectionAppearanceForViewport('story', $normalized, 'tablet')['mediaPlacement']);
            $this->assertSame('left', $template->resolveSectionAppearanceForViewport('story', $normalized, 'desktop')['mediaPlacement']);
        }

        $mobile = $template->responsiveControlFor('story', 'portraitStory', 'mobile', 'mediaPlacement');
        $this->assertSame('top', $mobile['default']);
        $this->assertSame(['top', 'bottom'], array_column($mobile['options'], 'key'));
        $this->assertSame(['top', 'bottom'], array_column($template->responsiveControlFor('hero', 'classic', 'tablet', 'mediaPlacement')['options'], 'key'));
    }

    public function test_classic_venue_details_first_tablet_supports_all_semantic_placements(): void
    {
        $template = (new WebsiteTemplateRegistry)->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $control = $template->responsiveControlFor('venue', 'detailsFirst', 'tablet', 'mediaPlacement');

        $this->assertSame('top', $control['default']);
        $this->assertSame(['top', 'bottom', 'left', 'right'], array_column($control['options'], 'key'));

        foreach (['top', 'bottom', 'left', 'right'] as $placement) {
            $normalized = $template->normalizeSectionAppearance('venue', [
                ...WebsiteSectionAppearance::DEFAULT,
                'presentation' => 'detailsFirst',
                'mediaPlacement' => 'right',
                'responsive' => ['tablet' => ['mediaPlacement' => $placement]],
            ]);

            $this->assertSame($placement, $normalized['responsive']['tablet']['mediaPlacement']);
            $this->assertSame($placement, $template->resolveSectionAppearanceForViewport('venue', $normalized, 'tablet')['mediaPlacement']);
            $this->assertSame('right', $template->resolveSectionAppearanceForViewport('venue', $normalized, 'desktop')['mediaPlacement']);
        }

        $mobile = $template->responsiveControlFor('venue', 'detailsFirst', 'mobile', 'mediaPlacement');
        $this->assertSame('top', $mobile['default']);
        $this->assertSame(['top', 'bottom'], array_column($mobile['options'], 'key'));
        $this->assertSame(['top', 'bottom'], array_column($template->responsiveControlFor('hero', 'classic', 'tablet', 'mediaPlacement')['options'], 'key'));
    }

    public function test_appearance_normalization_uses_explicit_defaults_when_inherit_is_unavailable(): void
    {
        $template = (new WebsiteTemplateRegistry)->get(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $options = $template->sectionAppearanceOptions;
        $defaults = $template->sectionAppearanceDefaults;
        $options['hero']['backgroundTreatments'] = [
            ['key' => 'soft', 'displayName' => 'Soft'],
            ['key' => 'plain', 'displayName' => 'Plain'],
        ];
        $defaults['hero']['backgroundTreatment'] = 'plain';
        $fixture = new WebsiteTemplateDefinition(
            key: 'explicit-fallback-test',
            displayName: 'Explicit fallback test',
            description: 'Test only.',
            styleTags: ['Test'],
            enabled: true,
            supportedEventTypes: $template->supportedEventTypes,
            supportedSectionTypes: $template->supportedSectionTypes,
            designOptions: $template->designOptions,
            defaultDesignSettings: $template->defaultDesignSettings,
            sectionAppearanceOptions: $options,
            sectionAppearanceDefaults: $defaults,
        );

        $normalized = $fixture->normalizeSectionAppearance('hero', [
            ...WebsiteSectionAppearance::DEFAULT,
            'backgroundTreatment' => 'accent',
        ]);

        $this->assertSame('plain', $normalized['backgroundTreatment']);
    }

    public function test_design_normalization_is_target_driven_for_shared_missing_and_invalid_values(): void
    {
        $template = (new WebsiteTemplateRegistry)->get(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);

        $this->assertSame([
            'colorTheme' => 'ink',
            'fontSet' => 'editorial',
            'artStyle' => 'clean',
        ], $template->normalizeDesignSettings([
            'colorTheme' => '',
            'fontSet' => 'editorial',
        ]));
    }

    public function test_registry_validation_rejects_a_default_outside_its_option_group(): void
    {
        $valid = (new WebsiteTemplateRegistry)->get(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $invalid = new WebsiteTemplateDefinition(
            key: 'invalid-test-template',
            displayName: 'Invalid test Template',
            description: 'Test only.',
            styleTags: ['Test'],
            enabled: true,
            supportedEventTypes: $valid->supportedEventTypes,
            supportedSectionTypes: $valid->supportedSectionTypes,
            designOptions: $valid->designOptions,
            defaultDesignSettings: [...$valid->defaultDesignSettings, 'colorTheme' => 'missing'],
            sectionAppearanceOptions: $valid->sectionAppearanceOptions,
            sectionAppearanceDefaults: $valid->sectionAppearanceDefaults,
        );

        $this->expectException(LogicException::class);
        (new WebsiteTemplateRegistry([$invalid->key => $invalid]))->assertValid();
    }

    public function test_modern_editorial_has_product_metadata_and_complete_wedding_capabilities(): void
    {
        $template = (new WebsiteTemplateRegistry)->get(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);

        $this->assertTrue($template->enabled);
        $this->assertSame('Modern Editorial', $template->displayName);
        $this->assertSame(['Modern', 'Editorial', 'Minimal'], $template->styleTags);
        $this->assertSame([EventType::Wedding], $template->supportedEventTypes);
        $this->assertSame(array_keys((new WebsiteSectionRegistry)->all()), $template->supportedSectionTypes);
        $this->assertSame(['colorTheme' => 'ink', 'fontSet' => 'editorial', 'artStyle' => 'clean'], $template->defaultDesignSettings);

        foreach ($template->supportedSectionTypes as $sectionType) {
            $this->assertSame(WebsiteSectionAppearance::OPTIONS, $template->appearanceOptionsFor($sectionType));
        }
    }

    public function test_registry_support_checks_fail_safely_for_unknown_templates_and_sections(): void
    {
        $registry = new WebsiteTemplateRegistry;
        $templateKey = WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1;

        $this->assertNull($registry->get('unknown-template'));
        $this->assertFalse($registry->supportsEventType('unknown-template', EventType::Wedding));
        $this->assertFalse($registry->supportsSection('unknown-template', 'hero'));
        $this->assertFalse($registry->supportsSection($templateKey, 'customLegacySection'));
        $this->assertFalse($registry->isCompatible('unknown-template', EventType::Wedding, []));
        $this->assertTrue($registry->isCompatible($templateKey, EventType::Wedding, ['hero', 'gallery']));
        $this->assertFalse($registry->isCompatible($templateKey, EventType::Wedding, ['hero', 'customLegacySection']));
    }

    public function test_every_template_capability_is_a_canonical_section_type(): void
    {
        $sectionKeys = array_keys((new WebsiteSectionRegistry)->all());

        foreach ((new WebsiteTemplateRegistry)->all() as $template) {
            $this->assertSame([], array_diff($template->supportedSectionTypes, $sectionKeys));
            $this->assertSame($template->supportedSectionTypes, array_values(array_unique($template->supportedSectionTypes)));
        }
    }

    public function test_classic_filipiniana_exposes_curated_appearance_capabilities_per_section(): void
    {
        $template = (new WebsiteTemplateRegistry)->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);

        foreach ($template->supportedSectionTypes as $sectionType) {
            $this->assertSame(WebsiteSectionAppearance::OPTIONS, $template->appearanceOptionsFor($sectionType));
        }
        $this->assertNull($template->appearanceOptionsFor('customLegacySection'));
    }

    public function test_templates_expose_valid_section_specific_presentation_capabilities(): void
    {
        $registry = new WebsiteTemplateRegistry;
        $expected = [
            WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1 => [
                'hero' => ['classic', 'immersive'],
                'story' => ['textFirst', 'portraitStory'],
                'venue' => ['detailsFirst', 'scenic'],
                'people' => ['medallions', 'portraitCards', 'namesOnly'],
            ],
            WebsiteTemplateRegistry::MODERN_EDITORIAL_V1 => [
                'hero' => ['editorial', 'immersive'],
                'story' => ['textFirst', 'editorial'],
                'venue' => ['detailsFirst', 'scenic'],
                'people' => ['editorialPortraits', 'squareGrid', 'minimal', 'namesOnly'],
            ],
        ];

        foreach ($expected as $templateKey => $sections) {
            $template = $registry->get($templateKey);
            foreach ($sections as $sectionType => $keys) {
                $capability = $template->presentationCapabilityFor($sectionType);
                $this->assertSame($keys, array_column($capability['options'], 'key'));
                $this->assertContains($capability['default'], $keys);
            }
            $this->assertNull($template->presentationCapabilityFor('faq'));
        }

        $registry->assertValid();
    }

    public function test_legacy_framed_presentations_normalize_to_layout_presentations_with_frame_intent(): void
    {
        $registry = new WebsiteTemplateRegistry;
        $cases = [
            [WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'hero', 'classic', 'fineLine'],
            [WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'story', 'textFirst', 'fineLine'],
            [WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'venue', 'detailsFirst', 'fineLine'],
            [WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, 'hero', 'editorial', 'hairline'],
            [WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, 'story', 'textFirst', 'hairline'],
            [WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, 'venue', 'detailsFirst', 'hairline'],
        ];

        foreach ($cases as [$templateKey, $sectionType, $presentation, $frameStyle]) {
            $normalized = $registry->get($templateKey)->normalizeSectionAppearance($sectionType, [
                ...WebsiteSectionAppearance::DEFAULT,
                'presentation' => 'framed',
                'cornerStyle' => 'rounded',
                'shadowStyle' => 'soft',
                'mediaContentGap' => 'spacious',
                'mediaSpacing' => ['top' => 'small', 'right' => 'medium', 'bottom' => 'large', 'left' => 'none'],
            ]);
            $this->assertSame($presentation, $normalized['presentation']);
            $this->assertSame($frameStyle, $normalized['frameStyle']);
            $this->assertSame('rounded', $normalized['cornerStyle']);
            $this->assertSame('soft', $normalized['shadowStyle']);
            $this->assertSame('spacious', $normalized['mediaContentGap']);
        }

        $people = $registry->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)->normalizeSectionAppearance('people', [
            ...WebsiteSectionAppearance::DEFAULT,
            'presentation' => 'framed',
        ]);
        $this->assertSame('portraitCards', $people['presentation']);
        $this->assertArrayNotHasKey('frameStyle', $people);
    }

    public function test_templates_expose_distinct_semantic_frame_catalogs_without_framed_presentations(): void
    {
        $registry = new WebsiteTemplateRegistry;
        $classic = $registry->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $modern = $registry->get(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);

        $this->assertSame(
            ['none', 'fineLine', 'doubleLine', 'inset', 'outset', 'heritage', 'ornamental'],
            array_column($classic->mediaControlsFor('story', 'portraitStory')['frameStyles']['options'], 'key'),
        );
        $this->assertSame(
            ['none', 'hairline', 'offset', 'gallery', 'boldEdge', 'outset', 'editorialFrame'],
            array_column($modern->mediaControlsFor('story', 'editorial')['frameStyles']['options'], 'key'),
        );
        $this->assertNotContains('framed', array_column($classic->presentationCapabilityFor('story')['options'], 'key'));
        $this->assertNotContains('framed', array_column($modern->presentationCapabilityFor('story')['options'], 'key'));
    }
}
