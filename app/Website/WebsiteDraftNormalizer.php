<?php

namespace App\Website;

use App\Exceptions\UnsupportedWebsiteSchemaVersion;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\Capabilities\WebsiteCapabilityResolver;

final class WebsiteDraftNormalizer
{
    public function __construct(
        private readonly WebsiteSectionContentNormalizer $sectionContent,
        private readonly WebsiteCapabilityResolver $capabilities,
        private readonly StoryContentNormalizer $storyContent,
    ) {}

    /**
     * @return array{
     *   schemaVersion: int,
     *   id: string,
     *   eventId: string,
     *   name: string,
     *   templateKey: string,
     *   designSettings: array<string, mixed>,
     *   projectDesignDefaults: array{headingFontId: string, bodyFontId: string, headingColorId: string, bodyColorId: string, accentColorId: string}|null,
     *   sections: list<array{section: WebsiteSection, content: array<string, mixed>}>
     * }
     */
    public function normalize(Website $website): array
    {
        $sourceVersion = $website->schema_version;
        if ($sourceVersion < WebsiteSchema::LEGACY_SCHEMA_VERSION || $sourceVersion > WebsiteSchema::CURRENT_SCHEMA_VERSION) {
            throw new UnsupportedWebsiteSchemaVersion($sourceVersion, WebsiteSchema::CURRENT_SCHEMA_VERSION);
        }

        $website->loadMissing('sections.website');
        $storedDesignSettings = $website->design_settings;
        $designSettings = $this->capabilities->normalizeDesignSettings($website->template_key, $storedDesignSettings);
        $resolved = $this->capabilities->resolveProjectDesignDefaults(
            $website->template_key,
            is_array($storedDesignSettings) ? $storedDesignSettings : [],
        );

        $wireVersion = $sourceVersion >= WebsiteSchema::CURRENT_SCHEMA_VERSION
            ? WebsiteSchema::CURRENT_SCHEMA_VERSION
            : max(2, $sourceVersion);

        return [
            'schemaVersion' => $wireVersion,
            'id' => $website->id,
            'eventId' => $website->event_id,
            'name' => $website->name,
            'templateKey' => $website->template_key,
            'designSettings' => $wireVersion === 2
                ? array_intersect_key($designSettings ?? (is_array($storedDesignSettings) ? $storedDesignSettings : []), array_flip(['colorTheme', 'fontSet', 'artStyle']))
                : ($designSettings === null ? $storedDesignSettings : [
                    ...$designSettings,
                    'projectDefaults' => (object) $designSettings['projectDefaults'],
                ]),
            'projectDesignDefaults' => $resolved === null ? null : [
                'headingFontId' => $resolved->headingFontId,
                'bodyFontId' => $resolved->bodyFontId,
                'headingColorId' => $resolved->headingColorId,
                'bodyColorId' => $resolved->bodyColorId,
                'accentColorId' => $resolved->accentColorId,
            ],
            'sections' => $website->sections->map(fn (WebsiteSection $section): array => [
                'section' => $section,
                'content' => $section->type === 'story' && $wireVersion === WebsiteSchema::CURRENT_SCHEMA_VERSION
                    ? $this->storyContent->normalizeToV4($section->id, $section->content)
                    : $this->sectionContent->normalize($section->id, $section->type, $section->content),
            ])->values()->all(),
        ];
    }
}
