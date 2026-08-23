<?php

namespace App\Actions\Websites;

use App\Models\MediaAsset;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionContentValidator;
use App\Website\WebsiteSectionMediaReferences;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Validation\ValidationException;

final class UpdateWebsiteSectionContent
{
    public function __construct(
        private readonly WebsiteSectionContentValidator $validator,
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteSectionMediaReferences $mediaReferences,
        private readonly UpgradeWebsiteProjectSchema $upgradeSchema,
    ) {}

    /** @param array<string, mixed> $content */
    public function handle(WebsiteSection $section, array $content): WebsiteSection
    {
        if ($section->type === 'story') {
            return $this->upgradeSchema->handle($section, $content);
        }

        $validated = $this->validator->validate($section->type, $content);
        $currentMedia = $section->content['media'] ?? null;
        $nextMedia = $validated['media'] ?? null;
        $website = $section->website()->firstOrFail();
        if ($section->type !== 'story' && $currentMedia !== $nextMedia && $this->templates->get($website->template_key)?->mediaCapabilityFor($section->type) === null) {
            throw ValidationException::withMessages(['content.media' => 'This Template does not support Media for this Section.']);
        }
        if (is_array($nextMedia) && ! MediaAsset::query()->whereKey($nextMedia['assetId'])->where('event_id', $website->event_id)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])->exists()) {
            throw ValidationException::withMessages(['content.media.assetId' => 'Select a valid image from this Event Media Library.']);
        }
        $nextItemReferences = $this->mediaReferences->extract($section->type, $validated);
        $currentReferences = $this->mediaReferences->extract($section->type, $section->content);
        if ($section->type === 'people' && $this->personMedia($section->content) !== $this->personMedia($validated) && $this->templates->get($website->template_key)?->itemMediaCapabilityFor($section->type) === null) {
            throw ValidationException::withMessages(['content.groups' => 'This Template does not support images for people in this Section.']);
        }
        $assetIds = collect($nextItemReferences)->pluck('assetId')->unique()->values();
        if ($assetIds->isNotEmpty() && MediaAsset::query()->where('event_id', $website->event_id)->whereKey($assetIds)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])->count() !== $assetIds->count()) {
            throw ValidationException::withMessages(['content.groups' => 'Select valid images from this Event Media Library.']);
        }
        $section->content = $validated;
        $section->save();

        return $section;
    }

    /** @param array<string, mixed> $content */
    private function personMedia(array $content): array
    {
        return collect($content['groups'] ?? [])->flatMap(fn (array $group): array => $group['people'] ?? [])
            ->mapWithKeys(fn (array $person): array => [(string) ($person['id'] ?? '') => $person['media'] ?? null])
            ->all();
    }
}
