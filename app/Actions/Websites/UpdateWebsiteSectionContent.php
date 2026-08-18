<?php

namespace App\Actions\Websites;

use App\Models\MediaAsset;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionContentValidator;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Validation\ValidationException;

final class UpdateWebsiteSectionContent
{
    public function __construct(private readonly WebsiteSectionContentValidator $validator, private readonly WebsiteTemplateRegistry $templates) {}

    /** @param array<string, mixed> $content */
    public function handle(WebsiteSection $section, array $content): WebsiteSection
    {
        $validated = $this->validator->validate($section->type, $content);
        $currentMedia = $section->content['media'] ?? null;
        $nextMedia = $validated['media'] ?? null;
        $website = $section->website()->firstOrFail();
        if ($currentMedia !== $nextMedia && $this->templates->get($website->template_key)?->mediaCapabilityFor($section->type) === null) {
            throw ValidationException::withMessages(['content.media' => 'This Template does not support Media for this Section.']);
        }
        if (is_array($nextMedia) && ! MediaAsset::query()->whereKey($nextMedia['assetId'])->where('event_id', $website->event_id)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])->exists()) {
            throw ValidationException::withMessages(['content.media.assetId' => 'Select a valid image from this Event Media Library.']);
        }
        $section->content = $validated;
        $section->save();

        return $section;
    }
}
