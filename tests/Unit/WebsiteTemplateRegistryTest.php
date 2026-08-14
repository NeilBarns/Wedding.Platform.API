<?php

namespace Tests\Unit;

use App\Enums\EventType;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateRegistry;
use PHPUnit\Framework\TestCase;

class WebsiteTemplateRegistryTest extends TestCase
{
    public function test_registry_exposes_only_classic_filipiniana_v1_for_weddings(): void
    {
        $registry = new WebsiteTemplateRegistry;
        $definitions = $registry->all();
        $template = $registry->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);

        $this->assertSame([WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1], array_keys($definitions));
        $this->assertSame('Classic Filipiniana', $template->displayName);
        $this->assertSame([EventType::Wedding], $template->supportedEventTypes);
        $this->assertSame(array_keys((new WebsiteSectionRegistry)->all()), $template->supportedSectionTypes);
        $this->assertSame(array_keys($definitions), array_keys($registry->forEventType(EventType::Wedding)));
        $this->assertSame($template->key, $registry->defaultForEventType(EventType::Wedding)->key);
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
}
