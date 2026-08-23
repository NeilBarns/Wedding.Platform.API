<?php

namespace App\Website;

final class StoryContentNormalizer
{
    /**
     * @param  array<string, mixed>  $content
     * @return array{heading: string, intro: string|null, elements: list<array<string, mixed>>, mediaFraming: array<string, mixed>}
     */
    public function normalize(string $sectionId, array $content): array
    {
        if (array_key_exists('elements', $content)) {
            return [
                'heading' => (string) ($content['heading'] ?? ''),
                'intro' => isset($content['intro']) ? (string) $content['intro'] : null,
                'elements' => is_array($content['elements']) ? array_values($content['elements']) : [],
                'mediaFraming' => is_array($content['mediaFraming'] ?? null) ? $content['mediaFraming'] : [],
            ];
        }

        if (array_key_exists('blocks', $content)) {
            $elements = [];
            $mediaFraming = [];
            foreach (is_array($content['blocks']) ? $content['blocks'] : [] as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $id = (string) ($block['id'] ?? '');
                $element = [
                    'id' => $id,
                    'type' => 'narrativeBlock',
                    'body' => (string) ($block['body'] ?? ''),
                ];
                if (isset($block['heading'])) {
                    $element['heading'] = (string) $block['heading'];
                }
                $media = $block['media'] ?? null;
                if (is_array($media) && is_string($media['assetId'] ?? null)) {
                    $element['media'] = ['type' => 'image', 'mediaId' => $media['assetId']];
                    $framing = array_intersect_key($media, array_flip(['focalPoint', 'zoom']));
                    if ($framing !== []) {
                        $mediaFraming[$id] = $framing;
                    }
                }
                $elements[] = $element;
            }

            return [
                'heading' => (string) ($content['heading'] ?? ''),
                'intro' => isset($content['intro']) ? (string) $content['intro'] : null,
                'elements' => $elements,
                'mediaFraming' => $mediaFraming,
            ];
        }

        $body = (string) ($content['body'] ?? '');
        $media = $content['media'] ?? null;
        $hasLegacyBlock = trim($body) !== '' || (is_array($media) && is_string($media['assetId'] ?? null));

        $id = 'story-legacy-'.$sectionId;
        $hasMedia = is_array($media) && is_string($media['assetId'] ?? null);
        $element = ['id' => $id, 'type' => 'narrativeBlock', 'body' => $body];
        if ($hasMedia) {
            $element['media'] = ['type' => 'image', 'mediaId' => $media['assetId']];
        }
        $framing = $hasMedia ? array_intersect_key($media, array_flip(['focalPoint', 'zoom'])) : [];

        return [
            'heading' => (string) ($content['heading'] ?? ''),
            'intro' => null,
            'elements' => $hasLegacyBlock ? [$element] : [],
            'mediaFraming' => $framing === [] ? [] : [$id => $framing],
        ];
    }
}
