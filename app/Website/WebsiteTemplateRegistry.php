<?php

namespace App\Website;

use App\Enums\EventType;

final class WebsiteTemplateRegistry
{
    public const CLASSIC_FILIPINIANA_V1 = 'classic-filipiniana-v1';

    /** @param array<string, WebsiteTemplateDefinition>|null $definitions */
    public function __construct(private readonly ?array $definitions = null) {}

    /** @return array<string, WebsiteTemplateDefinition> */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $sectionTypes = [
            'hero', 'date', 'story', 'schedule', 'venue', 'dressCode', 'gallery', 'faq', 'rsvp',
        ];
        $definition = new WebsiteTemplateDefinition(
            key: self::CLASSIC_FILIPINIANA_V1,
            displayName: 'Classic Filipiniana',
            description: 'A classic, elegant, Filipino-inspired Wedding presentation.',
            styleTags: ['Classic', 'Elegant', 'Filipino-inspired'],
            enabled: true,
            supportedEventTypes: [EventType::Wedding],
            supportedSectionTypes: $sectionTypes,
            designOptions: [
                'colorThemes' => $this->options([
                    'terracotta' => 'Terracotta',
                    'olive' => 'Olive',
                    'sage' => 'Sage',
                    'burgundy' => 'Burgundy',
                    'neutral' => 'Warm Neutral',
                ]),
                'fontSets' => $this->options([
                    'editorial' => 'Editorial',
                    'romantic' => 'Romantic',
                    'modern' => 'Modern',
                ]),
                'artStyles' => $this->options([
                    'minimal' => 'Minimal',
                    'botanical' => 'Botanical',
                    'woven' => 'Woven',
                    'clean' => 'Clean',
                ]),
            ],
            defaultDesignSettings: [
                'colorTheme' => 'terracotta',
                'fontSet' => 'editorial',
                'artStyle' => 'minimal',
            ],
            sectionAppearanceOptions: array_fill_keys($sectionTypes, WebsiteSectionAppearance::OPTIONS),
        );

        return [$definition->key => $definition];
    }

    public function get(string $key): ?WebsiteTemplateDefinition
    {
        return $this->all()[$key] ?? null;
    }

    /** @return array<string, WebsiteTemplateDefinition> */
    public function forEventType(EventType $eventType): array
    {
        return array_filter(
            $this->all(),
            fn (WebsiteTemplateDefinition $definition): bool => $definition->enabled && $definition->supportsEventType($eventType),
        );
    }

    public function defaultForEventType(EventType $eventType): ?WebsiteTemplateDefinition
    {
        return match ($eventType) {
            EventType::Wedding => $this->get(self::CLASSIC_FILIPINIANA_V1),
        };
    }

    public function supportsEventType(string $templateKey, EventType $eventType): bool
    {
        return $this->get($templateKey)?->supportsEventType($eventType) ?? false;
    }

    public function supportsSection(string $templateKey, string $sectionType): bool
    {
        return $this->get($templateKey)?->supportsSection($sectionType) ?? false;
    }

    /** @param iterable<string> $enabledSectionTypes */
    public function isCompatible(string $templateKey, EventType $eventType, iterable $enabledSectionTypes): bool
    {
        $definition = $this->get($templateKey);

        if (! $definition?->supportsEventType($eventType)) {
            return false;
        }

        foreach ($enabledSectionTypes as $sectionType) {
            if (! $definition->supportsSection($sectionType)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<array{key: string, displayName: string}>
     */
    private function options(array $values): array
    {
        return array_map(
            fn (string $key, string $displayName): array => compact('key', 'displayName'),
            array_keys($values),
            array_values($values),
        );
    }
}
