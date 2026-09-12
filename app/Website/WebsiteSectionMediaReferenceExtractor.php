<?php

namespace App\Website;

final class WebsiteSectionMediaReferenceExtractor
{
    /** @param array<string, mixed> $content */
    public function extract(string $sectionId, string $sectionType, array $content): array
    {
        $references = match ($sectionType) {
            'hero' => $this->sectionMedia($content),
            default => [],
        };
        $this->appendElementMedia($references, $content['childFlow']['elements'] ?? []);

        return $references;
    }

    /** @param array<int, mixed> $elements */
    private function appendElementMedia(array &$references, mixed $elements): void
    {
        if (! is_array($elements)) {
            return;
        }
        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }
            if (($element['type'] ?? null) === 'media') {
                foreach (is_array($element['items'] ?? null) ? $element['items'] : [] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    if (is_string($item['mediaId'] ?? null)) {
                        $references[] = ['mediaId' => $item['mediaId'], 'reference' => ['type' => 'sectionMedia']];
                    }
                }
            }
            if (($element['type'] ?? null) === 'people') {
                array_push($references, ...$this->people($element));
            }
            if (($element['type'] ?? null) === 'compositionGroup') {
                foreach (BackgroundMedia::assetIds($element['backgroundMedia'] ?? null) as $backgroundMediaId) {
                    $references[] = ['mediaId' => $backgroundMediaId, 'reference' => ['type' => 'sectionMedia', 'elementId' => $element['id'] ?? '']];
                }
                $this->appendElementMedia($references, $element['children'] ?? []);
            }
        }
    }

    private function sectionMedia(array $content): array
    {
        return array_map(fn (string $mediaId): array => ['mediaId' => $mediaId, 'reference' => ['type' => 'sectionMedia']], BackgroundMedia::assetIds($content['backgroundMedia'] ?? null));
    }

    private function people(array $content): array
    {
        $references = [];
        foreach (is_array($content['groups'] ?? null) ? $content['groups'] : [] as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach (is_array($group['people'] ?? null) ? $group['people'] : [] as $person) {
                if (! is_array($person) || ! is_string($person['media']['assetId'] ?? null) || ! is_string($person['id'] ?? null)) {
                    continue;
                }
                $reference = ['type' => 'person', 'personId' => $person['id']];
                if (is_string($person['name'] ?? null) && trim($person['name']) !== '') {
                    $reference['label'] = $person['name'];
                }
                if (is_string($group['id'] ?? null)) {
                    $reference['groupId'] = $group['id'];
                }
                if (is_string($group['name'] ?? null) && trim($group['name']) !== '') {
                    $reference['groupLabel'] = $group['name'];
                }
                $references[] = ['mediaId' => $person['media']['assetId'], 'reference' => $reference];
            }
        }

        return $references;
    }
}
