<?php

namespace App\Website;

final class WebsiteSectionMediaReferences
{
    public function __construct(private readonly WebsiteSectionMediaReferenceExtractor $extractor) {}

    /**
     * @param  array<string, mixed>  $content
     * @return list<array{assetId: string, context?: array<string, string>}>
     */
    public function extract(string $sectionType, array $content): array
    {
        return array_map(function (array $item): array {
            $reference = $item['reference'];
            $context = match ($reference['type']) {
                'storyNarrativeBlock' => array_filter([
                    'blockId' => $reference['elementId'],
                    'blockHeading' => $reference['label'] ?? '',
                ], fn (string $value): bool => $value !== ''),
                'person' => array_filter([
                    'groupId' => $reference['groupId'] ?? '',
                    'groupName' => $reference['groupLabel'] ?? '',
                    'personId' => $reference['personId'],
                    'personName' => $reference['label'] ?? '',
                ], fn (string $value): bool => $value !== ''),
                default => [],
            };

            return array_filter([
                'assetId' => $item['mediaId'],
                'context' => $context === [] ? null : $context,
            ], fn (mixed $value): bool => $value !== null);
        }, $this->extractor->extract('', $sectionType, $content));
    }
}
