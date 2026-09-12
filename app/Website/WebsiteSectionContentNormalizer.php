<?php

namespace App\Website;

use DomainException;

final class WebsiteSectionContentNormalizer
{
    public function __construct(private readonly WebsiteSectionRegistry $sections) {}

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
            'hero', 'gallery', 'rsvp', 'blank' => $content,
            default => throw new DomainException("Website section type [{$sectionType}] has no runtime content adapter."),
        };
    }
}
