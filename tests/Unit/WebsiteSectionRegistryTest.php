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
            'date',
            'story',
            'schedule',
            'venue',
            'dressCode',
            'people',
            'gallery',
            'faq',
            'rsvp',
        ], array_keys($definitions));
        $this->assertSame([10, 20, 30, 40, 50, 60, 65, 70, 80, 90], array_column($definitions, 'defaultOrder'));
        $this->assertSame(array_keys($definitions), array_keys($registry->forEventType(EventType::Wedding)));
        $this->assertSame(array_keys($definitions), array_keys($registry->defaultCompositionFor(EventType::Wedding)));
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
