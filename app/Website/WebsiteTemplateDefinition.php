<?php

namespace App\Website;

use App\Enums\EventType;

final readonly class WebsiteTemplateDefinition
{
    /**
     * @param  list<EventType>  $supportedEventTypes
     * @param  list<string>  $supportedSectionTypes
     * @param  array{colorThemes: list<array{key: string, displayName: string}>, fontSets: list<array{key: string, displayName: string}>, artStyles: list<array{key: string, displayName: string}>}  $designOptions
     * @param  array{colorTheme: string, fontSet: string, artStyle: string}  $defaultDesignSettings
     */
    public function __construct(
        public string $key,
        public string $displayName,
        public array $supportedEventTypes,
        public array $supportedSectionTypes,
        public array $designOptions,
        public array $defaultDesignSettings,
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
