<?php

namespace App\Website;

use App\Enums\EventType;

final class WebsiteSectionRegistry
{
    /** @return array<string, WebsiteSectionDefinition> */
    public function all(): array
    {
        return [
            'hero' => $this->definition('hero', 'Hero', 10, ['headline' => '', 'subheadline' => '']),
            'date' => $this->definition('date', 'Date', 20, ['heading' => '', 'description' => '']),
            'story' => $this->definition('story', 'Story', 30, ['heading' => '', 'intro' => null, 'elements' => [], 'mediaFraming' => []]),
            'schedule' => $this->definition('schedule', 'Schedule', 40, ['heading' => '', 'items' => []]),
            'venue' => $this->definition('venue', 'Venue', 50, ['heading' => '', 'name' => '', 'address' => '', 'description' => '']),
            'dressCode' => $this->definition('dressCode', 'Dress Code', 60, ['heading' => '', 'description' => '']),
            'people' => $this->definition('people', 'Wedding Party', 65, ['heading' => 'Wedding Party', 'groups' => []]),
            'gallery' => $this->definition('gallery', 'Gallery', 70, ['heading' => '', 'items' => []]),
            'faq' => $this->definition('faq', 'FAQ', 80, ['heading' => '', 'items' => []]),
            'rsvp' => $this->definition('rsvp', 'RSVP', 90, ['heading' => '', 'description' => '', 'buttonLabel' => '']),
            'blank' => $this->definition('blank', 'Section', 100, ['childFlow' => ['elements' => [], 'order' => []]], WebsiteSectionLifecycle::UserOwnedRepeatable),
        ];
    }

    public function get(string $key): ?WebsiteSectionDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, WebsiteSectionDefinition> */
    public function forEventType(EventType $eventType): array
    {
        return array_filter(
            $this->all(),
            fn (WebsiteSectionDefinition $definition): bool => $definition->supports($eventType),
        );
    }

    public function supports(EventType $eventType, string $sectionType): bool
    {
        return $this->get($sectionType)?->supports($eventType) ?? false;
    }

    /** @return array<string, WebsiteSectionDefinition> */
    public function defaultCompositionFor(EventType $eventType): array
    {
        return array_filter(
            $this->forEventType($eventType),
            fn (WebsiteSectionDefinition $definition): bool => $definition->lifecycle->isRequired(),
        );
    }

    /** @param array<string, mixed> $defaultContent */
    private function definition(string $key, string $displayName, int $defaultOrder, array $defaultContent, WebsiteSectionLifecycle $lifecycle = WebsiteSectionLifecycle::RequiredSingleton): WebsiteSectionDefinition
    {
        return new WebsiteSectionDefinition(
            key: $key,
            displayName: $displayName,
            supportedEventTypes: [EventType::Wedding],
            defaultEnabled: true,
            defaultOrder: $defaultOrder,
            defaultContent: $defaultContent,
            lifecycle: $lifecycle,
        );
    }
}
