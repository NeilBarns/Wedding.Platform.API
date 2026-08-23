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
    ) {}

    /**
     * @return array{
     *   schemaVersion: int,
     *   id: string,
     *   eventId: string,
     *   name: string,
     *   templateKey: string,
     *   designSettings: array<string, mixed>,
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
        $designSettings = is_array($storedDesignSettings)
            ? $this->capabilities->normalizeGlobalDesignSettings($website->template_key, $storedDesignSettings)
            : $this->capabilities->globalDesignDefaults($website->template_key);

        return [
            'schemaVersion' => WebsiteSchema::CURRENT_SCHEMA_VERSION,
            'id' => $website->id,
            'eventId' => $website->event_id,
            'name' => $website->name,
            'templateKey' => $website->template_key,
            'designSettings' => $designSettings ?? $storedDesignSettings,
            'sections' => $website->sections->map(fn (WebsiteSection $section): array => [
                'section' => $section,
                'content' => $this->sectionContent->normalize($section->id, $section->type, $section->content),
            ])->values()->all(),
        ];
    }
}
