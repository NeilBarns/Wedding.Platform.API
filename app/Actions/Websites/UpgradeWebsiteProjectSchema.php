<?php

namespace App\Actions\Websites;

use App\Exceptions\UnsupportedWebsiteSchemaVersion;
use App\Models\MediaAsset;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\StoryContentNormalizer;
use App\Website\WebsiteSchema;
use App\Website\WebsiteSectionContentValidator;
use App\Website\WebsiteSectionMediaReferences;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpgradeWebsiteProjectSchema
{
    public function __construct(
        private readonly StoryContentNormalizer $normalizer,
        private readonly WebsiteSectionContentValidator $validator,
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteCapabilityResolver $capabilities,
        private readonly WebsiteSectionMediaReferences $mediaReferences,
    ) {}

    /** @param array<string, mixed> $content */
    public function handle(WebsiteSection $section, array $content): WebsiteSection
    {
        return DB::transaction(function () use ($section, $content): WebsiteSection {
            $website = Website::query()->lockForUpdate()->findOrFail($section->website_id);
            if ($website->schema_version < WebsiteSchema::LEGACY_SCHEMA_VERSION || $website->schema_version > WebsiteSchema::CURRENT_SCHEMA_VERSION) {
                throw new UnsupportedWebsiteSchemaVersion($website->schema_version, WebsiteSchema::CURRENT_SCHEMA_VERSION);
            }

            $lockedSection = WebsiteSection::query()
                ->where('website_id', $website->id)
                ->whereKey($section->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Prove the currently stored generation can be represented by the v2 contract.
            $current = $this->validator->validate('story', $this->normalizer->normalize($lockedSection->id, $lockedSection->content));
            $validated = $this->validator->validate('story', $content);
            $this->validateMedia($website, $current, $validated);

            $storageContent = $validated;
            if ($storageContent['mediaFraming'] === []) {
                $storageContent['mediaFraming'] = new \stdClass;
            }
            $lockedSection->content = $storageContent;
            $lockedSection->save();

            if ($website->schema_version !== WebsiteSchema::CURRENT_SCHEMA_VERSION) {
                $website->design_settings = $this->capabilities->designSettingsForStorage($website->template_key, $website->design_settings)
                    ?? $website->design_settings;
                $website->schema_version = WebsiteSchema::CURRENT_SCHEMA_VERSION;
                $website->save();
            }

            return $lockedSection;
        });
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $next
     */
    private function validateMedia(Website $website, array $current, array $next): void
    {
        $currentReferences = $this->mediaReferences->extract('story', $current);
        $nextReferences = $this->mediaReferences->extract('story', $next);
        if ($currentReferences !== $nextReferences && $this->templates->get($website->template_key)?->mediaCapabilityFor('story') === null) {
            throw ValidationException::withMessages(['content.elements' => 'This Template does not support Media for Story elements.']);
        }

        $assetIds = collect($nextReferences)->pluck('assetId')->unique()->values();
        if ($assetIds->isNotEmpty() && MediaAsset::query()->where('event_id', $website->event_id)->whereKey($assetIds)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])->count() !== $assetIds->count()) {
            throw ValidationException::withMessages(['content.elements' => 'Select valid images from this Event Media Library.']);
        }
    }
}
