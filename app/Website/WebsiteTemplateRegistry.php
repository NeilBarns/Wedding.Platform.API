<?php

namespace App\Website;

use App\Enums\EventType;
use LogicException;

final class WebsiteTemplateRegistry
{
    public const CLASSIC_FILIPINIANA_V1 = 'classic-filipiniana-v1';

    public const MODERN_EDITORIAL_V1 = 'modern-editorial-v1';

    /** @param array<string, WebsiteTemplateDefinition>|null $definitions */
    public function __construct(private readonly ?array $definitions = null) {}

    /** @return array<string, WebsiteTemplateDefinition> */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $sectionTypes = [
            'hero', 'date', 'story', 'schedule', 'venue', 'dressCode', 'people', 'gallery', 'faq', 'rsvp',
        ];
        $classic = new WebsiteTemplateDefinition(
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
            sectionAppearanceDefaults: array_fill_keys($sectionTypes, WebsiteSectionAppearance::DEFAULT),
            sectionMediaCapabilities: array_fill_keys(['hero', 'story', 'venue'], ['mode' => 'single']),
            sectionItemMediaCapabilities: ['people' => ['itemType' => 'person', 'mode' => 'single']],
            sectionPresentationCapabilities: [
                'hero' => $this->presentation('classic', [
                    'classic' => ['Classic', 'Elegant centered composition with a restrained image.', 'contained'],
                    'immersive' => ['Immersive', 'Image-forward composition with closely integrated text.', 'overlay'],
                    'framed' => ['Framed', 'Formal framed image with generous breathing room.', 'frame'],
                ]),
                'story' => $this->presentation('framed', [
                    'textFirst' => ['Text First', 'Story-led composition with supporting photography.', 'text'],
                    'portraitStory' => ['Portrait Story', 'Portrait and narrative share the composition.', 'split'],
                    'framed' => ['Framed', 'A restrained photograph introduces the story.', 'frame'],
                ]),
                'venue' => $this->presentation('framed', [
                    'detailsFirst' => ['Details First', 'Venue information leads with supporting imagery.', 'text'],
                    'scenic' => ['Scenic', 'The venue photograph becomes the visual focus.', 'overlay'],
                    'framed' => ['Framed', 'Formal image treatment above the venue details.', 'frame'],
                ]),
                'people' => $this->presentation('medallions', [
                    'medallions' => ['Medallions', 'Formal circular portraits with ceremonial character.', 'circles'],
                    'portraitCards' => ['Portrait Cards', 'Elegant vertical portraits with names beneath.', 'cards'],
                    'framed' => ['Framed', 'Structured formal portraits in restrained frames.', 'grid'],
                    'namesOnly' => ['Names Only', 'A polished text-only Wedding Party composition.', 'text'],
                ]),
            ],
        );

        $modern = new WebsiteTemplateDefinition(
            key: self::MODERN_EDITORIAL_V1,
            displayName: 'Modern Editorial',
            description: 'A clean, contemporary Wedding layout with bold editorial typography and generous whitespace.',
            styleTags: ['Modern', 'Editorial', 'Minimal'],
            enabled: true,
            supportedEventTypes: [EventType::Wedding],
            supportedSectionTypes: $sectionTypes,
            designOptions: [
                'colorThemes' => $this->options([
                    'ink' => 'Ink',
                    'stone' => 'Stone',
                    'blush' => 'Blush',
                    'plum' => 'Plum',
                    'navy' => 'Navy',
                ]),
                'fontSets' => $this->options([
                    'editorial' => 'Editorial',
                    'fashion' => 'Fashion',
                    'minimal' => 'Minimal',
                ]),
                'artStyles' => $this->options([
                    'clean' => 'Clean',
                    'rule' => 'Rule',
                    'frame' => 'Frame',
                    'offset' => 'Offset',
                ]),
            ],
            defaultDesignSettings: [
                'colorTheme' => 'ink',
                'fontSet' => 'editorial',
                'artStyle' => 'clean',
            ],
            sectionAppearanceOptions: array_fill_keys($sectionTypes, WebsiteSectionAppearance::OPTIONS),
            sectionAppearanceDefaults: array_fill_keys($sectionTypes, WebsiteSectionAppearance::DEFAULT),
            sectionMediaCapabilities: array_fill_keys(['hero', 'story', 'venue'], ['mode' => 'single']),
            sectionItemMediaCapabilities: ['people' => ['itemType' => 'person', 'mode' => 'single']],
            sectionPresentationCapabilities: [
                'hero' => $this->presentation('immersive', [
                    'editorial' => ['Editorial', 'Asymmetric magazine-style image and typography.', 'split'],
                    'immersive' => ['Immersive', 'A large image-led opening composition.', 'overlay'],
                    'framed' => ['Framed', 'A controlled art-directed image block.', 'frame'],
                ]),
                'story' => $this->presentation('framed', [
                    'textFirst' => ['Text First', 'Narrative typography leads the composition.', 'text'],
                    'editorial' => ['Editorial', 'Image and story form an asymmetric spread.', 'split'],
                    'framed' => ['Framed', 'A strong image block introduces the story.', 'frame'],
                ]),
                'venue' => $this->presentation('framed', [
                    'detailsFirst' => ['Details First', 'Location details lead in an editorial layout.', 'text'],
                    'scenic' => ['Scenic', 'A broad venue image anchors the Section.', 'overlay'],
                    'framed' => ['Framed', 'A precise image block pairs with venue details.', 'frame'],
                ]),
                'people' => $this->presentation('editorialPortraits', [
                    'editorialPortraits' => ['Editorial Portraits', 'Vertical portraits with bold editorial rhythm.', 'cards'],
                    'squareGrid' => ['Square Grid', 'Structured square portraits in a clean grid.', 'grid'],
                    'minimal' => ['Minimal', 'Restrained, image-light portraits with generous space.', 'minimal'],
                    'namesOnly' => ['Names Only', 'A typographic Wedding Party composition.', 'text'],
                ]),
            ],
        );

        return [$classic->key => $classic, $modern->key => $modern];
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

    public function assertValid(): void
    {
        $canonicalSections = array_keys((new WebsiteSectionRegistry)->all());
        $seenKeys = [];
        $designGroups = ['colorTheme' => 'colorThemes', 'fontSet' => 'fontSets', 'artStyle' => 'artStyles'];
        $appearanceGroups = [
            'headingAlignment' => 'headingAlignments',
            'bodyAlignment' => 'bodyAlignments',
            'backgroundTreatment' => 'backgroundTreatments',
            'emphasis' => 'emphasisOptions',
        ];

        foreach ($this->all() as $registeredKey => $definition) {
            if (trim($definition->key) === '' || $registeredKey !== $definition->key || in_array($definition->key, $seenKeys, true)) {
                throw new LogicException('Template keys must be non-empty, unique, and match their registry keys.');
            }
            $seenKeys[] = $definition->key;

            if (trim($definition->displayName) === '' || $definition->supportedEventTypes === []) {
                throw new LogicException("Template [{$definition->key}] requires a display name and supported Event type.");
            }
            if ($definition->supportedSectionTypes === [] || array_diff($definition->supportedSectionTypes, $canonicalSections) !== [] || count($definition->supportedSectionTypes) !== count(array_unique($definition->supportedSectionTypes))) {
                throw new LogicException("Template [{$definition->key}] has invalid supported Sections.");
            }

            foreach ($designGroups as $setting => $group) {
                $this->assertOptionGroup($definition->key, $group, $definition->designOptions[$group] ?? []);
                $allowed = array_column($definition->designOptions[$group], 'key');
                if (! in_array($definition->defaultDesignSettings[$setting] ?? null, $allowed, true)) {
                    throw new LogicException("Template [{$definition->key}] has an invalid default [{$setting}].");
                }
            }

            foreach ($definition->supportedSectionTypes as $sectionType) {
                $options = $definition->appearanceOptionsFor($sectionType) ?? [];
                $defaults = $definition->appearanceDefaultsFor($sectionType) ?? [];
                foreach ($appearanceGroups as $setting => $group) {
                    $this->assertOptionGroup($definition->key, "{$sectionType}.{$group}", $options[$group] ?? []);
                    if (! in_array($defaults[$setting] ?? null, array_column($options[$group], 'key'), true)) {
                        throw new LogicException("Template [{$definition->key}] has an invalid Appearance default [{$sectionType}.{$setting}].");
                    }
                }
            }
            foreach ($definition->sectionMediaCapabilities as $sectionType => $capability) {
                if (! $definition->supportsSection($sectionType) || ! in_array($capability['mode'] ?? null, ['single', 'multiple'], true)) {
                    throw new LogicException("Template [{$definition->key}] has invalid Media capability [{$sectionType}].");
                }
            }
            foreach ($definition->sectionItemMediaCapabilities as $sectionType => $capability) {
                if (! $definition->supportsSection($sectionType) || ($capability['itemType'] ?? null) !== 'person' || ($capability['mode'] ?? null) !== 'single') {
                    throw new LogicException("Template [{$definition->key}] has invalid item Media capability [{$sectionType}].");
                }
            }
            foreach ($definition->sectionPresentationCapabilities as $sectionType => $capability) {
                if (! $definition->supportsSection($sectionType)) {
                    throw new LogicException("Template [{$definition->key}] has invalid presentation capability [{$sectionType}].");
                }
                $this->assertOptionGroup($definition->key, "{$sectionType}.presentations", $capability['options'] ?? []);
                if (! in_array($capability['default'] ?? null, array_column($capability['options'] ?? [], 'key'), true)) {
                    throw new LogicException("Template [{$definition->key}] has an invalid presentation default [{$sectionType}].");
                }
                foreach ($capability['options'] as $option) {
                    if (trim($option['description'] ?? '') === '' || trim($option['preview'] ?? '') === '') {
                        throw new LogicException("Template [{$definition->key}] has invalid presentation metadata [{$sectionType}].");
                    }
                }
            }
        }
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

    /**
     * @param  array<string, array{string, string, string}>  $values
     * @return array{default: string, options: list<array{key: string, displayName: string, description: string, preview: string}>}
     */
    private function presentation(string $default, array $values): array
    {
        return [
            'default' => $default,
            'options' => array_map(
                fn (string $key, array $metadata): array => [
                    'key' => $key,
                    'displayName' => $metadata[0],
                    'description' => $metadata[1],
                    'preview' => $metadata[2],
                ],
                array_keys($values),
                array_values($values),
            ),
        ];
    }

    /** @param list<array{key: string, displayName: string}> $options */
    private function assertOptionGroup(string $templateKey, string $group, array $options): void
    {
        $keys = array_column($options, 'key');
        if ($options === [] || count($keys) !== count(array_unique($keys))) {
            throw new LogicException("Template [{$templateKey}] has an empty or duplicate option group [{$group}].");
        }
        foreach ($options as $option) {
            if (trim($option['key'] ?? '') === '' || trim($option['displayName'] ?? '') === '') {
                throw new LogicException("Template [{$templateKey}] has invalid option metadata in [{$group}].");
            }
        }
    }
}
