<?php

namespace App\Website;

use DomainException;

final class WebsiteSectionContentNormalizer
{
    public function __construct(
        private readonly WebsiteSectionRegistry $sections,
        private readonly StoryContentNormalizer $story,
    ) {}

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function normalize(string $sectionId, string $sectionType, array $content): array
    {
        if ($this->sections->get($sectionType) === null) {
            throw new DomainException("Website section type [{$sectionType}] has no runtime content adapter.");
        }

        return match ($sectionType) {
            'story' => $this->story->normalize($sectionId, $content),
            'hero', 'date', 'schedule', 'venue', 'dressCode', 'people', 'gallery', 'faq', 'rsvp' => $content,
            default => throw new DomainException("Website section type [{$sectionType}] has no runtime content adapter."),
        };
    }
}
