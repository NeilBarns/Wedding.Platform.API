<?php

namespace App\Actions\Websites;

use App\Exceptions\UnsupportedWebsiteSchemaVersion;
use App\Models\MediaAsset;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\Elements\NarrativeBlockV4Validator;
use App\Website\StoryContentNormalizer;
use App\Website\WebsiteSchema;
use App\Website\WebsiteSectionContentValidator;
use App\Website\WebsiteSectionMediaReferences;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpgradeWebsiteProjectSchema
{
    public function __construct(
        private readonly StoryContentNormalizer $normalizer,
        private readonly WebsiteSectionContentValidator $validator,
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteCapabilityResolver $capabilities,
        private readonly WebsiteSectionMediaReferences $mediaReferences,
        private readonly NarrativeBlockV4Validator $narrativeV4,
    ) {}

    /** @param array<string, mixed> $content */
    public function handle(WebsiteSection $section, array $content, ?int $requestedSchemaVersion = null): WebsiteSection
    {
        return DB::transaction(function () use ($section, $content, $requestedSchemaVersion): WebsiteSection {
            $website = Website::query()->lockForUpdate()->findOrFail($section->website_id);
            if ($website->schema_version < WebsiteSchema::LEGACY_SCHEMA_VERSION || $website->schema_version > WebsiteSchema::CURRENT_SCHEMA_VERSION) {
                throw new UnsupportedWebsiteSchemaVersion($website->schema_version, WebsiteSchema::CURRENT_SCHEMA_VERSION);
            }

            $lockedSection = WebsiteSection::query()
                ->where('website_id', $website->id)
                ->whereKey($section->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($requestedSchemaVersion !== null && $requestedSchemaVersion !== $website->schema_version && $requestedSchemaVersion !== WebsiteSchema::CURRENT_SCHEMA_VERSION) {
                throw ValidationException::withMessages(['schemaVersion' => 'The requested schema version does not match this Website.']);
            }

            $promoting = $requestedSchemaVersion === WebsiteSchema::CURRENT_SCHEMA_VERSION
                && $website->schema_version < WebsiteSchema::CURRENT_SCHEMA_VERSION;
            $isV4 = $website->schema_version === WebsiteSchema::CURRENT_SCHEMA_VERSION || $promoting;
            $current = $isV4
                ? $this->validateV4Story($this->normalizer->normalizeToV4($lockedSection->id, $lockedSection->content))
                : $this->validator->validate('story', $this->normalizer->normalize($lockedSection->id, $lockedSection->content));
            $validated = $isV4
                ? $this->validateV4Story($content)
                : $this->validator->validate('story', $content);
            $this->validateMedia($website, $current, $validated);

            if ($promoting) {
                foreach (WebsiteSection::query()->where('website_id', $website->id)->where('type', 'story')->lockForUpdate()->get() as $story) {
                    $canonical = $story->is($lockedSection)
                        ? $validated
                        : $this->validateV4Story($this->normalizer->normalizeToV4($story->id, $story->content));
                    $story->content = $this->storageContent($canonical);
                    $story->save();
                }

                $website->design_settings = $this->capabilities->designSettingsForStorage($website->template_key, $website->design_settings)
                    ?? $website->design_settings;
                $website->schema_version = WebsiteSchema::CURRENT_SCHEMA_VERSION;
                $website->save();

                return $lockedSection->fresh();
            }

            $lockedSection->content = $this->storageContent($validated);
            $lockedSection->save();

            if ($website->schema_version < 3) {
                $website->design_settings = $this->capabilities->designSettingsForStorage($website->template_key, $website->design_settings)
                    ?? $website->design_settings;
                $website->schema_version = 3;
                $website->save();
            }

            return $lockedSection;
        });
    }

    /** @param array<string, mixed> $content */
    private function validateV4Story(array $content): array
    {
        if (array_key_exists('heading', $content) && $content['heading'] === null) {
            $content['heading'] = '';
        }
        $validated = Validator::make(['content' => $content], [
            'content' => ['required', 'array:heading,intro,elements,mediaFraming'],
            'content.heading' => ['present', 'string', 'max:255'],
            'content.intro' => ['present', 'nullable', 'string', 'max:1000'],
            'content.elements' => ['present', 'array', 'list', 'max:20'],
            'content.elements.*' => ['required', 'array'],
            'content.mediaFraming' => ['present', 'array'],
        ])->validate()['content'];
        $validated['elements'] = array_map(fn (array $element): array => $this->narrativeV4->validate($element), $validated['elements']);
        $ids = array_column($validated['elements'], 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['content.elements' => 'Narrative Block IDs must be unique.']);
        }

        return $validated;
    }

    /** @param array<string, mixed> $content */
    private function storageContent(array $content): array
    {
        if ($content['mediaFraming'] === []) {
            $content['mediaFraming'] = new \stdClass;
        }

        return $content;
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
