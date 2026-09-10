<?php

namespace Tests\Unit;

use App\Enums\EventType;
use App\Website\WebsiteSectionRegistry;
use PHPUnit\Framework\TestCase;

class WebsiteSectionRegistryTest extends TestCase
{
    public function test_registry_exposes_the_canonical_wedding_composition(): void
    {
        $registry = new WebsiteSectionRegistry;
        $definitions = $registry->all();

        $this->assertSame([
            'hero',
            'story',
            'people',
            'gallery',
            'rsvp',
            'blank',
        ], array_keys($definitions));
        $this->assertSame([10, 30, 65, 70, 90, 100], array_column($definitions, 'defaultOrder'));
        $this->assertSame(array_keys($definitions), array_keys($registry->forEventType(EventType::Wedding)));
        $this->assertSame(['hero', 'story', 'people', 'gallery', 'rsvp'], array_keys($registry->defaultCompositionFor(EventType::Wedding)));
        $this->assertNull($registry->get('faq'));
        $this->assertNull($registry->get('date'));
        $this->assertNull($registry->get('dressCode'));
        $this->assertNull($registry->get('schedule'));
        $this->assertNull($registry->get('venue'));
        $this->assertTrue($definitions['hero']->lifecycle->isSingleton());
        $this->assertTrue($definitions['blank']->lifecycle->isUserOwned());
        $this->assertCount(count($definitions), array_unique(array_map(
            fn ($definition) => $definition->key,
            $definitions,
        )));
    }

    public function test_registry_lookups_are_deliberate_and_content_is_semantic(): void
    {
        $registry = new WebsiteSectionRegistry;
        $presentationKeys = ['templateKey', 'componentName', 'cssClass', 'layout', 'font', 'background', 'color'];

        $this->assertNull($registry->get('unknown'));
        $this->assertFalse($registry->supports(EventType::Wedding, 'unknown'));
        $this->assertTrue($registry->supports(EventType::Wedding, 'hero'));

        foreach ($registry->all() as $definition) {
            $this->assertIsArray($definition->defaultContent);
            $this->assertSame([], array_intersect($presentationKeys, array_keys($definition->defaultContent)));
        }
    }
}
