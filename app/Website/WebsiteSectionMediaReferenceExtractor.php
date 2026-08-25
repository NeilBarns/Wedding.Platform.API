<?php

namespace App\Website;

final class WebsiteSectionMediaReferenceExtractor
{
    /** @param array<string, mixed> $content */
    public function extract(string $sectionId, string $sectionType, array $content): array
    {
        return match ($sectionType) {
            'hero', 'venue' => $this->sectionMedia($content),
            'story' => $this->story($sectionId, $content),
            'people' => $this->people($content),
            default => [],
        };
    }

    private function sectionMedia(array $content): array
    {
        $mediaId = $content['media']['assetId'] ?? null;

        return is_string($mediaId) ? [['mediaId' => $mediaId, 'reference' => ['type' => 'sectionMedia']]] : [];
    }

    private function story(string $sectionId, array $content): array
    {
        $references = [];
        if (array_key_exists('elements', $content)) {
            foreach (is_array($content['elements']) ? $content['elements'] : [] as $element) {
                if (! is_array($element) || ($element['type'] ?? null) !== 'narrativeBlock') {
                    continue;
                }
                $canonicalMedia = $element['slots']['media']['content'] ?? null;
                if (is_array($canonicalMedia) && in_array($canonicalMedia['type'] ?? null, ['image', 'video'], true)) {
                    $this->appendStory($references, $canonicalMedia['mediaId'] ?? null, $element['id'] ?? null, $element['slots']['heading']['text'] ?? null);
                } elseif (is_array($canonicalMedia) && ($canonicalMedia['type'] ?? null) === 'mediaCollection') {
                    foreach ($canonicalMedia['items'] ?? [] as $item) {
                        $this->appendStory($references, $item['mediaId'] ?? null, $element['id'] ?? null, $element['slots']['heading']['text'] ?? null);
                    }
                } elseif (($element['media']['type'] ?? null) === 'image') {
                    $this->appendStory($references, $element['media']['mediaId'] ?? null, $element['id'] ?? null, $element['heading'] ?? null);
                }
            }

            return $references;
        }
        if (array_key_exists('blocks', $content)) {
            foreach (is_array($content['blocks']) ? $content['blocks'] : [] as $block) {
                if (is_array($block)) {
                    $this->appendStory($references, $block['media']['assetId'] ?? null, $block['id'] ?? null, $block['heading'] ?? null);
                }
            }

            return $references;
        }
        $this->appendStory($references, $content['media']['assetId'] ?? null, 'story-legacy-'.$sectionId, null);

        return $references;
    }

    private function appendStory(array &$references, mixed $mediaId, mixed $elementId, mixed $heading): void
    {
        if (! is_string($mediaId) || ! is_string($elementId)) {
            return;
        }
        $reference = ['type' => 'storyNarrativeBlock', 'elementId' => $elementId];
        if (is_string($heading) && trim($heading) !== '') {
            $reference['label'] = $heading;
        }
        $references[] = ['mediaId' => $mediaId, 'reference' => $reference];
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
