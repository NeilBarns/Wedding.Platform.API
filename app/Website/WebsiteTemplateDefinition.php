<?php

namespace App\Website;

use App\Enums\EventType;

final readonly class WebsiteTemplateDefinition
{
    /**
     * @param  list<EventType>  $supportedEventTypes
     * @param  list<string>  $supportedSectionTypes
     */
    public function __construct(
        public string $key,
        public string $displayName,
        public array $supportedEventTypes,
        public array $supportedSectionTypes,
    ) {}

    public function supportsEventType(EventType $eventType): bool
    {
        return in_array($eventType, $this->supportedEventTypes, true);
    }

    public function supportsSection(string $sectionType): bool
    {
        return in_array($sectionType, $this->supportedSectionTypes, true);
    }
}
