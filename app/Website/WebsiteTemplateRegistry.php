<?php

namespace App\Website;

use App\Enums\EventType;
use App\Website\Capabilities\DesignColorCapability;
use App\Website\Capabilities\DesignColorRole;
use App\Website\Capabilities\FontFamilyCapability;
use App\Website\Capabilities\PalettePresetCapability;
use App\Website\Capabilities\ProjectColorRole;
use App\Website\Capabilities\TemplateDesignLibrary;
use App\Website\Capabilities\TypographyPresetCapability;
use App\Website\Capabilities\TypographyRole;
use LogicException;

final class WebsiteTemplateRegistry
{
    public const CLASSIC_FILIPINIANA_V1 = 'classic-filipiniana-v1';

    public const MODERN_EDITORIAL_V1 = 'modern-editorial-v1';

    /** @param  array<string, WebsiteTemplateDefinition>|null  $definitions */
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
        $classicDesignLibrary = $this->classicDesignLibrary();
        $classic = new WebsiteTemplateDefinition(
            key: self::CLASSIC_FILIPINIANA_V1,
            displayName: 'Classic Filipiniana',
            description: 'A classic, elegant, Filipino-inspired Wedding presentation.',
            styleTags: ['Classic', 'Elegant', 'Filipino-inspired'],
            enabled: true,
            supportedEventTypes: [EventType::Wedding],
            supportedSectionTypes: $sectionTypes,
            designOptions: [
                'colorThemes' => $this->presetOptions($classicDesignLibrary->palettePresets),
                'fontSets' => $this->presetOptions($classicDesignLibrary->typographyPresets),
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
            designLibrary: $classicDesignLibrary,
            sectionAppearanceOptions: array_fill_keys($sectionTypes, WebsiteSectionAppearance::OPTIONS),
            sectionAppearanceDefaults: array_fill_keys($sectionTypes, WebsiteSectionAppearance::DEFAULT),
            sectionMediaCapabilities: [
                'hero' => ['mode' => 'single'],
                'story' => ['mode' => 'multiple'],
                'venue' => ['mode' => 'single'],
            ],
            sectionItemMediaCapabilities: ['people' => ['itemType' => 'person', 'mode' => 'single']],
            sectionPresentationCapabilities: [
                'hero' => $this->presentation('classic', [
                    'classic' => ['Classic', 'Elegant centered composition with a restrained image.', 'contained'],
                    'immersive' => ['Immersive', 'Image-forward composition with closely integrated text.', 'overlay'],
                ], $this->mediaControls('classic', 'hero')),
                'story' => $this->presentation('portraitStory', [
                    'textFirst' => ['Text First', 'Story-led composition with supporting photography.', 'text'],
                    'portraitStory' => ['Portrait Story', 'Portrait and narrative share the composition.', 'split'],
                ], $this->mediaControls('classic', 'story')),
                'venue' => $this->presentation('detailsFirst', [
                    'detailsFirst' => ['Details First', 'Venue information leads with supporting imagery.', 'text'],
                    'scenic' => ['Scenic', 'The venue photograph becomes the visual focus.', 'overlay'],
                ], $this->mediaControls('classic', 'venue')),
                'people' => $this->presentation('medallions', [
                    'medallions' => ['Medallions', 'Formal circular portraits with ceremonial character.', 'circles'],
                    'portraitCards' => ['Portrait Cards', 'Elegant vertical portraits with names beneath.', 'cards'],
                    'namesOnly' => ['Names Only', 'A polished text-only Wedding Party composition.', 'text'],
                ]),
            ],
            sectionPresentationFallbacks: [
                'hero' => ['framed' => ['presentation' => 'classic', 'frameStyle' => 'fineLine']],
                'story' => ['framed' => ['presentation' => 'textFirst', 'frameStyle' => 'fineLine']],
                'venue' => ['framed' => ['presentation' => 'detailsFirst', 'frameStyle' => 'fineLine']],
                'people' => ['framed' => ['presentation' => 'portraitCards']],
            ],
        );

        $modernDesignLibrary = $this->modernDesignLibrary();
        $modern = new WebsiteTemplateDefinition(
            key: self::MODERN_EDITORIAL_V1,
            displayName: 'Modern Editorial',
            description: 'A clean, contemporary Wedding layout with bold editorial typography and generous whitespace.',
            styleTags: ['Modern', 'Editorial', 'Minimal'],
            enabled: true,
            supportedEventTypes: [EventType::Wedding],
            supportedSectionTypes: $sectionTypes,
            designOptions: [
                'colorThemes' => $this->presetOptions($modernDesignLibrary->palettePresets),
                'fontSets' => $this->presetOptions($modernDesignLibrary->typographyPresets),
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
            designLibrary: $modernDesignLibrary,
            sectionAppearanceOptions: array_fill_keys($sectionTypes, WebsiteSectionAppearance::OPTIONS),
            sectionAppearanceDefaults: array_fill_keys($sectionTypes, WebsiteSectionAppearance::DEFAULT),
            sectionMediaCapabilities: [
                'hero' => ['mode' => 'single'],
                'story' => ['mode' => 'multiple'],
                'venue' => ['mode' => 'single'],
            ],
            sectionItemMediaCapabilities: ['people' => ['itemType' => 'person', 'mode' => 'single']],
            sectionPresentationCapabilities: [
                'hero' => $this->presentation('immersive', [
                    'editorial' => ['Editorial', 'Asymmetric magazine-style image and typography.', 'split'],
                    'immersive' => ['Immersive', 'A large image-led opening composition.', 'overlay'],
                ], $this->mediaControls('modern', 'hero')),
                'story' => $this->presentation('editorial', [
                    'textFirst' => ['Text First', 'Narrative typography leads the composition.', 'text'],
                    'editorial' => ['Editorial', 'Image and story form an asymmetric spread.', 'split'],
                ], $this->mediaControls('modern', 'story')),
                'venue' => $this->presentation('detailsFirst', [
                    'detailsFirst' => ['Details First', 'Location details lead in an editorial layout.', 'text'],
                    'scenic' => ['Scenic', 'A broad venue image anchors the Section.', 'overlay'],
                ], $this->mediaControls('modern', 'venue')),
                'people' => $this->presentation('editorialPortraits', [
                    'editorialPortraits' => ['Editorial Portraits', 'Vertical portraits with bold editorial rhythm.', 'cards'],
                    'squareGrid' => ['Square Grid', 'Structured square portraits in a clean grid.', 'grid'],
                    'minimal' => ['Minimal', 'Restrained, image-light portraits with generous space.', 'minimal'],
                    'namesOnly' => ['Names Only', 'A typographic Wedding Party composition.', 'text'],
                ]),
            ],
            sectionPresentationFallbacks: [
                'hero' => ['framed' => ['presentation' => 'editorial', 'frameStyle' => 'hairline']],
                'story' => ['framed' => ['presentation' => 'textFirst', 'frameStyle' => 'hairline']],
                'venue' => ['framed' => ['presentation' => 'detailsFirst', 'frameStyle' => 'hairline']],
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

    /** @param  iterable<string>  $enabledSectionTypes */
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
            $this->assertDesignLibrary($definition);

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
                    $controls = $option['mediaControls'] ?? [];
                    foreach (['mediaPlacements', 'mediaSizes', 'frameStyles', 'cornerStyles', 'shadowStyles', 'foregroundColors', 'mediaContentGaps'] as $group) {
                        if (! isset($controls[$group])) {
                            continue;
                        }
                        $this->assertOptionGroup($definition->key, "{$sectionType}.{$option['key']}.{$group}", $controls[$group]['options'] ?? []);
                        if (! in_array($controls[$group]['default'] ?? null, array_column($controls[$group]['options'] ?? [], 'key'), true)) {
                            throw new LogicException("Template [{$definition->key}] has an invalid Media control default [{$sectionType}.{$option['key']}.{$group}].");
                        }
                    }
                    if (isset($controls['mediaSpacing'])) {
                        $this->assertOptionGroup($definition->key, "{$sectionType}.{$option['key']}.mediaSpacing", $controls['mediaSpacing']['options'] ?? []);
                        $allowed = array_column($controls['mediaSpacing']['options'] ?? [], 'key');
                        foreach (['top', 'right', 'bottom', 'left'] as $side) {
                            if (! in_array($controls['mediaSpacing']['default'][$side] ?? null, $allowed, true)) {
                                throw new LogicException("Template [{$definition->key}] has an invalid Media spacing default [{$sectionType}.{$option['key']}.{$side}].");
                            }
                        }
                    }
                    if (isset($controls['overlayStrength']) && (($controls['overlayStrength']['min'] ?? 1) >= ($controls['overlayStrength']['max'] ?? 0) || ($controls['overlayStrength']['default'] ?? -1) < $controls['overlayStrength']['min'] || $controls['overlayStrength']['default'] > $controls['overlayStrength']['max'])) {
                        throw new LogicException("Template [{$definition->key}] has an invalid overlay control [{$sectionType}.{$option['key']}].");
                    }
                    foreach ($controls['responsive'] ?? [] as $viewport => $responsiveControls) {
                        if (! in_array($viewport, WebsiteSectionAppearance::RESPONSIVE_VIEWPORTS, true)) {
                            throw new LogicException("Template [{$definition->key}] has an invalid responsive viewport [{$sectionType}.{$option['key']}.{$viewport}].");
                        }
                        foreach ($responsiveControls as $setting => $control) {
                            if (! in_array($setting, WebsiteSectionAppearance::RESPONSIVE_SETTINGS, true)) {
                                throw new LogicException("Template [{$definition->key}] has an invalid responsive control [{$sectionType}.{$option['key']}.{$setting}].");
                            }
                            $this->assertOptionGroup($definition->key, "{$sectionType}.{$option['key']}.{$viewport}.{$setting}", $control['options'] ?? []);
                            $allowed = array_column($control['options'] ?? [], 'key');
                            if ($setting === 'mediaSpacing') {
                                foreach (['top', 'right', 'bottom', 'left'] as $side) {
                                    if (! in_array($control['default'][$side] ?? null, $allowed, true)) {
                                        throw new LogicException("Template [{$definition->key}] has an invalid responsive Media spacing default [{$sectionType}.{$option['key']}.{$viewport}.{$side}].");
                                    }
                                }
                            } elseif (! in_array($control['default'] ?? null, $allowed, true)) {
                                throw new LogicException("Template [{$definition->key}] has an invalid responsive control default [{$sectionType}.{$option['key']}.{$viewport}.{$setting}].");
                            }
                        }
                    }
                }
            }
            foreach ($definition->sectionPresentationFallbacks as $sectionType => $fallbacks) {
                foreach ($fallbacks as $legacyPresentation => $fallback) {
                    $capability = $definition->presentationCapabilityFor($sectionType);
                    $target = $fallback['presentation'] ?? null;
                    if ($capability === null || in_array($legacyPresentation, array_column($capability['options'], 'key'), true) || ! in_array($target, array_column($capability['options'], 'key'), true)) {
                        throw new LogicException("Template [{$definition->key}] has an invalid legacy presentation fallback [{$sectionType}.{$legacyPresentation}].");
                    }
                    if (isset($fallback['frameStyle'])) {
                        $allowedFrames = array_column($definition->mediaControlsFor($sectionType, $target)['frameStyles']['options'] ?? [], 'key');
                        if (! in_array($fallback['frameStyle'], $allowedFrames, true)) {
                            throw new LogicException("Template [{$definition->key}] has an invalid legacy frame fallback [{$sectionType}.{$legacyPresentation}].");
                        }
                    }
                }
            }
        }
    }

    private function classicDesignLibrary(): TemplateDesignLibrary
    {
        return $this->designLibrary(
            palettes: [
                'terracotta' => ['displayName' => 'Terracotta', 'roles' => ['canvas' => '#f8f0e4', 'surface' => '#f1e5d5', 'text' => '#3b312d', 'textMuted' => '#6c5f57', 'accent' => '#9d5b45', 'accentContrast' => '#ffffff', 'border' => '#806d5e', 'ornament' => '#78805f']],
                'olive' => ['displayName' => 'Olive', 'roles' => ['canvas' => '#f4f1e5', 'surface' => '#e8e4d2', 'text' => '#34372c', 'textMuted' => '#626454', 'accent' => '#70764e', 'accentContrast' => '#ffffff', 'border' => '#74745f', 'ornament' => '#a06a4f']],
                'sage' => ['displayName' => 'Sage', 'roles' => ['canvas' => '#f1f3e9', 'surface' => '#e2e8d9', 'text' => '#303a33', 'textMuted' => '#5f6c63', 'accent' => '#748a70', 'accentContrast' => '#ffffff', 'border' => '#718075', 'ornament' => '#a86650']],
                'burgundy' => ['displayName' => 'Burgundy', 'roles' => ['canvas' => '#f7eeea', 'surface' => '#ecddda', 'text' => '#3d292d', 'textMuted' => '#715d61', 'accent' => '#7d3443', 'accentContrast' => '#ffffff', 'border' => '#82696d', 'ornament' => '#7b7955']],
                'neutral' => ['displayName' => 'Warm Neutral', 'roles' => ['canvas' => '#f5f1eb', 'surface' => '#e9e3dc', 'text' => '#35312e', 'textMuted' => '#69625d', 'accent' => '#74645a', 'accentContrast' => '#ffffff', 'border' => '#7c746e', 'ornament' => '#777363']],
            ],
            fontFamilies: [
                new FontFamilyCapability('editorial-serif', 'Editorial Serif', [TypographyRole::Heading]),
                new FontFamilyCapability('modern-sans', 'Modern Sans', [TypographyRole::Heading, TypographyRole::Body]),
                new FontFamilyCapability('romantic-serif', 'Romantic Serif', [TypographyRole::Heading]),
                new FontFamilyCapability('classic-serif', 'Classic Serif', [TypographyRole::Body]),
            ],
            typographyPresets: [
                new TypographyPresetCapability('editorial', 'Editorial', 'editorial-serif', 'modern-sans'),
                new TypographyPresetCapability('romantic', 'Romantic', 'romantic-serif', 'classic-serif'),
                new TypographyPresetCapability('modern', 'Modern', 'modern-sans', 'modern-sans'),
            ],
        );
    }

    private function modernDesignLibrary(): TemplateDesignLibrary
    {
        return $this->designLibrary(
            palettes: [
                'ink' => ['displayName' => 'Ink', 'roles' => ['canvas' => '#f5f3ee', 'surface' => '#e9e6df', 'text' => '#171717', 'textMuted' => '#65615b', 'accent' => '#171717', 'accentContrast' => '#ffffff', 'border' => '#908a80']],
                'stone' => ['displayName' => 'Stone', 'roles' => ['canvas' => '#f3f1ed', 'surface' => '#e4e0da', 'text' => '#302e2a', 'textMuted' => '#716c64', 'accent' => '#686158', 'accentContrast' => '#ffffff', 'border' => '#999188']],
                'blush' => ['displayName' => 'Blush', 'roles' => ['canvas' => '#faf3f1', 'surface' => '#f0dfdc', 'text' => '#3b292b', 'textMuted' => '#765f61', 'accent' => '#9c5f64', 'accentContrast' => '#ffffff', 'border' => '#b99191']],
                'plum' => ['displayName' => 'Plum', 'roles' => ['canvas' => '#f7f2f6', 'surface' => '#e9dde7', 'text' => '#302330', 'textMuted' => '#6e5a6c', 'accent' => '#5f405f', 'accentContrast' => '#ffffff', 'border' => '#927b8f']],
                'navy' => ['displayName' => 'Navy', 'roles' => ['canvas' => '#f1f4f7', 'surface' => '#dde4eb', 'text' => '#182432', 'textMuted' => '#596775', 'accent' => '#263c5a', 'accentContrast' => '#ffffff', 'border' => '#77889b']],
            ],
            fontFamilies: [
                new FontFamilyCapability('editorial-serif', 'Editorial Serif', [TypographyRole::Heading]),
                new FontFamilyCapability('modern-sans', 'Modern Sans', [TypographyRole::Heading, TypographyRole::Body]),
                new FontFamilyCapability('fashion-serif', 'Fashion Serif', [TypographyRole::Heading]),
                new FontFamilyCapability('fashion-sans', 'Fashion Sans', [TypographyRole::Body]),
            ],
            typographyPresets: [
                new TypographyPresetCapability('editorial', 'Editorial', 'editorial-serif', 'modern-sans'),
                new TypographyPresetCapability('fashion', 'Fashion', 'fashion-serif', 'fashion-sans'),
                new TypographyPresetCapability('minimal', 'Minimal', 'modern-sans', 'modern-sans'),
            ],
        );
    }

    /**
     * @param  array<string, array{displayName: string, roles: array<string, string>}>  $palettes
     * @param  list<FontFamilyCapability>  $fontFamilies
     * @param  list<TypographyPresetCapability>  $typographyPresets
     */
    private function designLibrary(array $palettes, array $fontFamilies, array $typographyPresets): TemplateDesignLibrary
    {
        $colors = [];
        $palettePresets = [];
        $roleLabels = ['canvas' => 'Canvas', 'surface' => 'Surface', 'text' => 'Text', 'textMuted' => 'Muted Text', 'accent' => 'Accent', 'accentContrast' => 'Accent Contrast', 'border' => 'Border', 'ornament' => 'Ornament'];

        foreach ($palettes as $presetId => $palette) {
            $roles = [];
            foreach ($palette['roles'] as $role => $value) {
                $colorId = $presetId.'-'.match ($role) {
                    'textMuted' => 'text-muted',
                    'accentContrast' => 'accent-contrast',
                    default => $role,
                };
                $allowedProjectRoles = match ($role) {
                    DesignColorRole::Text->value => [ProjectColorRole::Heading, ProjectColorRole::Body],
                    DesignColorRole::Accent->value => [ProjectColorRole::Heading, ProjectColorRole::Accent],
                    default => [],
                };
                $colors[] = new DesignColorCapability($colorId, $palette['displayName'].' '.$roleLabels[$role], $value, $allowedProjectRoles);
                $roles[$role] = $colorId;
            }
            $palettePresets[] = new PalettePresetCapability($presetId, $palette['displayName'], $roles);
        }

        return new TemplateDesignLibrary($colors, $fontFamilies, $palettePresets, $typographyPresets);
    }

    /** @param  list<PalettePresetCapability|TypographyPresetCapability>  $presets */
    private function presetOptions(array $presets): array
    {
        return array_map(fn (PalettePresetCapability|TypographyPresetCapability $preset): array => [
            'key' => $preset->id,
            'displayName' => $preset->displayName,
        ], $presets);
    }

    private function assertDesignLibrary(WebsiteTemplateDefinition $template): void
    {
        $library = $template->designLibrary;
        $colorIds = array_column($library->colors, 'id');
        $fontIds = array_column($library->fontFamilies, 'id');
        foreach ([$colorIds, $fontIds] as $ids) {
            if ($ids === [] || count($ids) !== count(array_unique($ids))) {
                throw new LogicException("Template [{$template->key}] has duplicate or empty Design Library IDs.");
            }
        }

        foreach ($library->colors as $color) {
            if (trim($color->id) === '' || trim($color->displayName) === '' || preg_match('/^#[0-9a-fA-F]{6}$/', $color->value) !== 1) {
                throw new LogicException("Template [{$template->key}] has an invalid Design Library color.");
            }
            if (count($color->allowedProjectRoles) !== count(array_unique($color->allowedProjectRoles, SORT_REGULAR))) {
                throw new LogicException("Template [{$template->key}] has duplicate Project color roles [{$color->id}].");
            }
        }

        $requiredRoles = array_map(fn (DesignColorRole $role): string => $role->value, array_filter(
            DesignColorRole::cases(),
            fn (DesignColorRole $role): bool => $role !== DesignColorRole::Ornament,
        ));
        $allowedRoles = array_map(fn (DesignColorRole $role): string => $role->value, DesignColorRole::cases());
        foreach ($library->palettePresets as $preset) {
            if (array_diff($requiredRoles, array_keys($preset->roles)) !== [] || array_diff(array_keys($preset->roles), $allowedRoles) !== []) {
                throw new LogicException("Template [{$template->key}] has invalid Palette roles [{$preset->id}].");
            }
            foreach ($preset->roles as $colorId) {
                if (! in_array($colorId, $colorIds, true)) {
                    throw new LogicException("Template [{$template->key}] Palette [{$preset->id}] references an unknown color.");
                }
            }
        }

        $families = collect($library->fontFamilies)->keyBy('id');
        foreach ($library->typographyPresets as $preset) {
            $heading = $families->get($preset->headingFontId);
            $body = $families->get($preset->bodyFontId);
            if (! ($heading instanceof FontFamilyCapability) || ! in_array(TypographyRole::Heading, $heading->allowedRoles, true)
                || ! ($body instanceof FontFamilyCapability) || ! in_array(TypographyRole::Body, $body->allowedRoles, true)) {
                throw new LogicException("Template [{$template->key}] has invalid Typography mapping [{$preset->id}].");
            }
        }

        if ($this->presetOptions($library->palettePresets) !== $template->designOptions['colorThemes']
            || $this->presetOptions($library->typographyPresets) !== $template->designOptions['fontSets']) {
            throw new LogicException("Template [{$template->key}] Design Library presets are out of sync with Design options.");
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
    private function presentation(string $default, array $values, array $controls = []): array
    {
        return [
            'default' => $default,
            'options' => array_map(
                fn (string $key, array $metadata): array => [
                    'key' => $key,
                    'displayName' => $metadata[0],
                    'description' => $metadata[1],
                    'preview' => $metadata[2],
                    'mediaControls' => $controls[$key] ?? null,
                ],
                array_keys($values),
                array_values($values),
            ),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function mediaControls(string $template, string $section): array
    {
        $frames = $template === 'classic'
            ? $this->options(['none' => 'None', 'fineLine' => 'Fine Line', 'doubleLine' => 'Double Line', 'inset' => 'Inset', 'outset' => 'Outset', 'heritage' => 'Heritage', 'ornamental' => 'Ornamental'])
            : $this->options(['none' => 'None', 'hairline' => 'Hairline', 'offset' => 'Offset', 'gallery' => 'Gallery', 'boldEdge' => 'Bold Edge', 'outset' => 'Outset', 'editorialFrame' => 'Editorial Frame']);
        $styles = fn (string $placement, string $frame = 'none', ?array $placements = null, ?string $mobilePlacement = null, ?array $tabletPlacements = null): array => [
            'mediaPlacements' => ['default' => $placement, 'options' => $this->options($placements ?? (in_array($placement, ['left', 'right'], true) ? ['left' => 'Left', 'right' => 'Right'] : ['top' => 'Top', 'bottom' => 'Bottom']))],
            'mediaSizes' => ['default' => 'balanced', 'options' => $this->options(['compact' => 'Compact', 'balanced' => 'Balanced', 'feature' => 'Feature'])],
            'frameStyles' => ['default' => $frame, 'options' => $frames],
            'cornerStyles' => ['default' => 'square', 'options' => $this->options(['square' => 'Square', 'soft' => 'Soft', 'rounded' => 'Rounded'])],
            'shadowStyles' => ['default' => 'none', 'options' => $this->options(['none' => 'None', 'subtle' => 'Subtle', 'soft' => 'Soft', 'elevated' => 'Elevated'])],
            'mediaSpacing' => [
                'default' => ['top' => 'medium', 'right' => 'medium', 'bottom' => 'medium', 'left' => 'medium'],
                'options' => $this->options(['none' => 'None', 'small' => 'Small', 'medium' => 'Medium', 'large' => 'Large']),
            ],
            'mediaContentGaps' => ['default' => 'comfortable', 'options' => $this->options(['tight' => 'Tight', 'comfortable' => 'Comfortable', 'spacious' => 'Spacious', 'generous' => 'Generous'])],
            ...($mobilePlacement === null ? [] : ['responsive' => [
                'tablet' => [
                    'mediaPlacement' => ['default' => $mobilePlacement, 'options' => $this->options($tabletPlacements ?? ['top' => 'Top', 'bottom' => 'Bottom'])],
                ],
                'mobile' => [
                    'mediaPlacement' => ['default' => $mobilePlacement, 'options' => $this->options(['top' => 'Top', 'bottom' => 'Bottom'])],
                ],
            ]]),
        ];
        $immersive = [
            'overlayStrength' => ['default' => 0.5, 'min' => 0.2, 'max' => 0.8, 'step' => 0.1],
            'foregroundColors' => ['default' => '#FFFFFF', 'options' => $this->options(['#FFFFFF' => 'Light', '#1F1713' => 'Dark'])],
        ];

        return match ([$section, $template]) {
            ['hero', 'classic'] => ['classic' => $styles('top', 'none', ['top' => 'Top', 'right' => 'Right', 'bottom' => 'Bottom', 'left' => 'Left'], 'top'), 'immersive' => $immersive],
            ['story', 'classic'] => ['textFirst' => $styles('bottom', 'none', null, 'bottom'), 'portraitStory' => $styles('left', 'none', null, 'top', ['top' => 'Top', 'bottom' => 'Bottom', 'left' => 'Left', 'right' => 'Right'])],
            ['venue', 'classic'] => ['detailsFirst' => $styles('right', 'none', null, 'top', ['top' => 'Top', 'bottom' => 'Bottom', 'left' => 'Left', 'right' => 'Right']), 'scenic' => $immersive],
            ['hero', 'modern'] => ['editorial' => $styles('left', 'none', null, 'top'), 'immersive' => $immersive],
            ['story', 'modern'] => ['textFirst' => $styles('bottom', 'none', null, 'bottom'), 'editorial' => $styles('left', 'none', null, 'top')],
            ['venue', 'modern'] => ['detailsFirst' => $styles('right', 'none', null, 'top'), 'scenic' => $immersive],
            default => [],
        };
    }

    /** @param  list<array{key: string, displayName: string}>  $options */
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
