<?php

namespace App\Actions\Websites;

use App\Exceptions\UnsupportedWebsiteSchemaVersion;
use App\Models\MediaAsset;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\Capabilities\ElementColorRole;
use App\Website\Capabilities\TypographyRole;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\Elements\NarrativeBlockValidator;
use App\Website\StoryContentNormalizer;
use App\Website\StoryStructureOrder;
use App\Website\WebsiteSchema;
use App\Website\WebsiteSectionMediaReferences;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpgradeWebsiteProjectSchema
{
    public function __construct(
        private readonly StoryContentNormalizer $normalizer,
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteCapabilityResolver $capabilities,
        private readonly WebsiteSectionMediaReferences $mediaReferences,
        private readonly NarrativeBlockValidator $narrative,
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

            $current = $this->validateCurrentStory($this->normalizer->normalizeToCurrent($lockedSection->id, $lockedSection->content), $website->template_key);
            $validated = $this->validateCurrentStory($content, $website->template_key);
            $this->validateMedia($website, $current, $validated);

            foreach (WebsiteSection::query()->where('website_id', $website->id)->where('type', 'story')->lockForUpdate()->get() as $story) {
                $canonical = $story->is($lockedSection)
                    ? $validated
                    : $this->validateCurrentStory($this->normalizer->normalizeToCurrent($story->id, $story->content), $website->template_key);
                $story->content = $this->storageContent($canonical);
                $story->save();
            }

            $website->design_settings = $this->capabilities->designSettingsForStorage($website->template_key, $website->design_settings)
                ?? $website->design_settings;
            $website->schema_version = WebsiteSchema::CURRENT_SCHEMA_VERSION;
            $website->save();

            return $lockedSection->fresh();
        });
    }

    /** @param array<string, mixed> $content */
    private function validateCurrentStory(array $content, string $templateKey): array
    {
        if (array_key_exists('heading', $content) && $content['heading'] === null) {
            $content['heading'] = '';
        }
        $validated = Validator::make(['content' => $content], [
            'content' => ['required', 'array:eyebrow,eyebrowIsHidden,heading,intro,headingIsHidden,introIsHidden,singletonAppearance,elements,mediaFraming,structureOrder'],
            'content.eyebrow' => ['sometimes', 'nullable', 'string', 'max:255'],
            'content.eyebrowIsHidden' => ['sometimes', 'boolean'],
            'content.heading' => ['present', 'string', 'max:255'],
            'content.intro' => ['present', 'nullable', 'string', 'max:1000'],
            'content.headingIsHidden' => ['sometimes', 'boolean'],
            'content.introIsHidden' => ['sometimes', 'boolean'],
            'content.singletonAppearance' => ['sometimes', 'array:eyebrow,heading,intro'],
            'content.singletonAppearance.*' => ['sometimes', 'array:fontFamilyId,fontSize,lineSpacing,letterSpacing,colorId,alignment'],
            'content.singletonAppearance.*.fontFamilyId' => ['sometimes', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'content.singletonAppearance.*.fontSize' => ['sometimes', 'array:desktop,tablet,mobile'],
            'content.singletonAppearance.*.fontSize.*' => ['sometimes', 'in:xs,s,m,l,xl'],
            'content.singletonAppearance.*.lineSpacing' => ['sometimes', 'in:tight,normal,relaxed'],
            'content.singletonAppearance.*.letterSpacing' => ['sometimes', 'in:tight,normal,wide'],
            'content.singletonAppearance.*.colorId' => ['sometimes', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'content.singletonAppearance.eyebrow.alignment' => ['sometimes', 'in:start,center,end'],
            'content.singletonAppearance.heading.alignment' => ['sometimes', 'in:start,center,end'],
            'content.singletonAppearance.intro.alignment' => ['sometimes', 'in:start,center,end'],
            'content.elements' => ['present', 'array', 'list', 'max:20'],
            'content.elements.*' => ['required', 'array'],
            'content.mediaFraming' => ['present', 'array'],
            'content.mediaFraming.*' => ['present', 'array:focalPoint,zoom'],
            'content.mediaFraming.*.focalPoint' => ['sometimes', 'array:x,y', 'required_array_keys:x,y'],
            'content.mediaFraming.*.focalPoint.x' => ['required_with:content.mediaFraming.*.focalPoint', 'numeric', 'between:0,1'],
            'content.mediaFraming.*.focalPoint.y' => ['required_with:content.mediaFraming.*.focalPoint', 'numeric', 'between:0,1'],
            'content.mediaFraming.*.zoom' => ['sometimes', 'numeric', 'between:1,3'],
            'content.structureOrder' => ['sometimes', 'array', 'list', 'max:23'],
            'content.structureOrder.*' => ['required', 'string'],
        ])->validate()['content'];
        $allowedFonts = $this->allowedFonts($templateKey);
        $allowedColors = $this->allowedColors($templateKey);
        foreach (['eyebrow' => 'body', 'heading' => 'heading', 'intro' => 'body'] as $field => $role) {
            $appearance = $validated['singletonAppearance'][$field] ?? [];
            $fontId = $appearance['fontFamilyId'] ?? null;
            if (is_string($fontId) && ! in_array($fontId, $allowedFonts[$role], true)) {
                throw ValidationException::withMessages(["content.singletonAppearance.{$field}.fontFamilyId" => 'The selected font is not supported for this role.']);
            }
            $colorId = $appearance['colorId'] ?? null;
            if (is_string($colorId) && ! in_array($colorId, $allowedColors[$role], true)) {
                throw ValidationException::withMessages(["content.singletonAppearance.{$field}.colorId" => 'The selected color is not supported for this role.']);
            }
        }
        $validated['elements'] = array_map(fn (array $element): array => $this->narrative->validate($element, $allowedFonts), $validated['elements']);
        $ids = array_column($validated['elements'], 'id');
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['content.elements' => 'Narrative Block IDs must be unique.']);
        }
        if (array_key_exists('structureOrder', $validated) && ! StoryStructureOrder::isCanonical($validated['structureOrder'], $ids)) {
            throw ValidationException::withMessages(['content.structureOrder' => 'Story structure order must be a complete canonical permutation.']);
        }
        $imageElementIds = collect($validated['elements'])
            ->filter(fn (array $element): bool => ($element['slots']['media']['content']['type'] ?? null) === 'image')
            ->pluck('id')
            ->flip();
        foreach ($validated['mediaFraming'] as $elementId => $_framing) {
            if (! $imageElementIds->has((string) $elementId)) {
                throw ValidationException::withMessages([
                    "content.mediaFraming.{$elementId}" => 'Framing must reference a Narrative Block with image media.',
                ]);
            }
        }

        return $validated;
    }

    /** @return array{heading: list<string>, body: list<string>} */
    private function allowedFonts(string $templateKey): array
    {
        $families = $this->templates->get($templateKey)?->designLibrary->fontFamilies ?? [];

        return [
            'heading' => array_values(array_map(fn ($font): string => $font->id, array_filter($families, fn ($font): bool => in_array(TypographyRole::Heading, $font->allowedRoles, true)))),
            'body' => array_values(array_map(fn ($font): string => $font->id, array_filter($families, fn ($font): bool => in_array(TypographyRole::Body, $font->allowedRoles, true)))),
        ];
    }

    /** @return array{heading: list<string>, body: list<string>} */
    private function allowedColors(string $templateKey): array
    {
        $appearance = collect($this->capabilities->template($templateKey)?->elementCapabilities)
            ->first(fn ($element): bool => $element->type->value === 'narrativeBlock')?->appearance;

        return [
            'heading' => collect($appearance?->colors)->first(fn ($color): bool => $color->role === ElementColorRole::HeadingColor)?->allowedColorIds ?? [],
            'body' => collect($appearance?->colors)->first(fn ($color): bool => $color->role === ElementColorRole::TextColor)?->allowedColorIds ?? [],
        ];
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
