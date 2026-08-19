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
     * @param  array<string, array<string, list<array{key: string, displayName: string}>>>  $sectionAppearanceOptions
     * @param  array<string, array{headingAlignment: string, bodyAlignment: string, backgroundTreatment: string, emphasis: string}>  $sectionAppearanceDefaults
     */
    public function __construct(
        public string $key,
        public string $displayName,
        public string $description,
        public array $styleTags,
        public bool $enabled,
        public array $supportedEventTypes,
        public array $supportedSectionTypes,
        public array $designOptions,
        public array $defaultDesignSettings,
        public array $sectionAppearanceOptions,
        public array $sectionAppearanceDefaults,
        public array $sectionMediaCapabilities = [],
        public array $sectionItemMediaCapabilities = [],
        public array $sectionPresentationCapabilities = [],
    ) {}

    /** @param array<string, mixed> $settings */
    public function normalizeDesignSettings(array $settings): array
    {
        $groups = ['colorTheme' => 'colorThemes', 'fontSet' => 'fontSets', 'artStyle' => 'artStyles'];
        $normalized = [];

        foreach ($groups as $setting => $group) {
            $allowed = array_column($this->designOptions[$group] ?? [], 'key');
            $current = $settings[$setting] ?? null;
            $normalized[$setting] = is_string($current) && in_array($current, $allowed, true)
                ? $current
                : $this->defaultDesignSettings[$setting];
        }

        return $normalized;
    }

    /** @param array<string, mixed> $appearance */
    public function normalizeSectionAppearance(string $sectionType, array $appearance): array
    {
        $groups = [
            'headingAlignment' => 'headingAlignments',
            'bodyAlignment' => 'bodyAlignments',
            'backgroundTreatment' => 'backgroundTreatments',
            'emphasis' => 'emphasisOptions',
        ];
        $options = $this->appearanceOptionsFor($sectionType) ?? [];
        $normalized = [];

        foreach ($groups as $setting => $group) {
            $allowed = array_column($options[$group] ?? [], 'key');
            $current = $appearance[$setting] ?? null;
            $fallback = $this->sectionAppearanceDefaults[$sectionType][$setting] ?? null;
            $normalized[$setting] = is_string($current) && in_array($current, $allowed, true)
                ? $current
                : $fallback;
        }

        $presentation = $this->presentationCapabilityFor($sectionType);
        if ($presentation !== null) {
            $allowed = array_column($presentation['options'], 'key');
            $current = $appearance['presentation'] ?? null;
            $normalized['presentation'] = is_string($current) && in_array($current, $allowed, true)
                ? $current
                : $presentation['default'];
        }

        return $normalized;
    }

    public function supportsEventType(EventType $eventType): bool
    {
        return in_array($eventType, $this->supportedEventTypes, true);
    }

    public function supportsSection(string $sectionType): bool
    {
        return in_array($sectionType, $this->supportedSectionTypes, true);
    }

    /** @return array<string, list<array{key: string, displayName: string}>>|null */
    public function appearanceOptionsFor(string $sectionType): ?array
    {
        return $this->sectionAppearanceOptions[$sectionType] ?? null;
    }

    /** @return array{headingAlignment: string, bodyAlignment: string, backgroundTreatment: string, emphasis: string}|null */
    public function appearanceDefaultsFor(string $sectionType): ?array
    {
        return $this->sectionAppearanceDefaults[$sectionType] ?? null;
    }

    /** @return array{mode: 'single'|'multiple'}|null */
    public function mediaCapabilityFor(string $sectionType): ?array
    {
        return $this->sectionMediaCapabilities[$sectionType] ?? null;
    }

    /** @return array{itemType: string, mode: 'single'}|null */
    public function itemMediaCapabilityFor(string $sectionType): ?array
    {
        return $this->sectionItemMediaCapabilities[$sectionType] ?? null;
    }

    /** @return array{default: string, options: list<array{key: string, displayName: string, description: string, preview: string}>}|null */
    public function presentationCapabilityFor(string $sectionType): ?array
    {
        return $this->sectionPresentationCapabilities[$sectionType] ?? null;
    }
}
