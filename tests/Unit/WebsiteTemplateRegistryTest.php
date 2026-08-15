<?php

namespace Tests\Unit;

use App\Enums\EventType;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateRegistry;
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
}
