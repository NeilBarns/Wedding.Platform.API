<?php

namespace App\Website;

final class StoryContentNormalizer
{
    /**
     * @param  array<string, mixed>  $content
     * @return array{eyebrow?: string|null, eyebrowIsHidden?: bool, heading: string, intro: string|null, headingIsHidden?: bool, introIsHidden?: bool, elements: list<array<string, mixed>>, mediaFraming: array<string, mixed>, structureOrder?: list<mixed>}
     */
    public function normalize(string $sectionId, array $content): array
    {
        if (array_key_exists('elements', $content)) {
            $normalized = [
                'heading' => (string) ($content['heading'] ?? ''),
                'intro' => isset($content['intro']) ? (string) $content['intro'] : null,
                'elements' => is_array($content['elements']) ? array_values($content['elements']) : [],
                'mediaFraming' => is_array($content['mediaFraming'] ?? null) ? $content['mediaFraming'] : [],
            ];
            if (array_key_exists('eyebrow', $content)) {
                $normalized['eyebrow'] = isset($content['eyebrow']) ? (string) $content['eyebrow'] : null;
            }
            foreach (['eyebrowIsHidden', 'headingIsHidden', 'introIsHidden'] as $visibility) {
                if (array_key_exists($visibility, $content)) {
                    $normalized[$visibility] = $content[$visibility] === true;
                }
            }
            if (isset($content['singletonAppearance']) && is_array($content['singletonAppearance'])) {
                $normalized['singletonAppearance'] = $content['singletonAppearance'];
            }
            $this->preserveCanonicalStructureOrder($content, $normalized);

            return $normalized;
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

            $normalized = [
                'heading' => (string) ($content['heading'] ?? ''),
                'intro' => isset($content['intro']) ? (string) $content['intro'] : null,
                'elements' => $elements,
                'mediaFraming' => $mediaFraming,
            ];
            if (array_key_exists('eyebrow', $content)) {
                $normalized['eyebrow'] = isset($content['eyebrow']) ? (string) $content['eyebrow'] : null;
            }
            foreach (['eyebrowIsHidden', 'headingIsHidden', 'introIsHidden'] as $visibility) {
                if (array_key_exists($visibility, $content)) {
                    $normalized[$visibility] = $content[$visibility] === true;
                }
            }
            $this->preserveCanonicalStructureOrder($content, $normalized);

            return $normalized;
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

        $normalized = [
            'heading' => (string) ($content['heading'] ?? ''),
            'intro' => null,
            'elements' => $hasLegacyBlock ? [$element] : [],
            'mediaFraming' => $framing === [] ? [] : [$id => $framing],
        ];
        if (array_key_exists('eyebrow', $content)) {
            $normalized['eyebrow'] = isset($content['eyebrow']) ? (string) $content['eyebrow'] : null;
        }
        foreach (['eyebrowIsHidden', 'headingIsHidden', 'introIsHidden'] as $visibility) {
            if (array_key_exists($visibility, $content)) {
                $normalized[$visibility] = $content[$visibility] === true;
            }
        }
        $this->preserveCanonicalStructureOrder($content, $normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $normalized
     */
    private function preserveCanonicalStructureOrder(array $source, array &$normalized): void
    {
        if (! array_key_exists('structureOrder', $source) || ! is_array($source['structureOrder'])) {
            return;
        }
        $order = array_values($source['structureOrder']);
        if (StoryStructureOrder::isCanonical($order, array_column($normalized['elements'], 'id'))) {
            $normalized['structureOrder'] = $order;
        }
    }

    /**
     * Normalize any supported legacy Story shape into the slot-based intermediate contract.
     * This method is pure and never persists the normalized value.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function normalizeLegacyShape(string $sectionId, array $content): array
    {
        $story = $this->normalize($sectionId, $content);
        $story['elements'] = array_map(
            fn (array $element): array => $this->normalizeLegacyNarrativeBlock($element),
            $story['elements'],
        );

        return $story;
    }

    /** @param array<string, mixed> $content */
    public function normalizeToCurrent(string $sectionId, array $content): array
    {
        $story = $this->normalizeLegacyShape($sectionId, $content);
        $story['elements'] = array_map(function (array $element): array {
            $element['composition'] ??= [];

            return $element;
        }, $story['elements']);

        return $story;
    }

    /** @param array<string, mixed> $element */
    public function normalizeLegacyNarrativeBlock(array $element): array
    {
        if (isset($element['slots']) && is_array($element['slots'])) {
            return $element;
        }

        $heading = array_key_exists('heading', $element) ? (string) $element['heading'] : '';
        $media = is_array($element['media'] ?? null) ? $element['media'] : null;

        return [
            'id' => (string) ($element['id'] ?? ''),
            'type' => 'narrativeBlock',
            'isHidden' => false,
            'slots' => [
                'eyebrow' => $this->textSlot('', true),
                'heading' => $this->textSlot($heading, false),
                'divider' => ['isHidden' => true],
                'body' => $this->textSlot((string) ($element['body'] ?? ''), false),
                'quote' => ['isHidden' => true, 'text' => ''],
                'media' => [
                    'isHidden' => $media === null,
                    'content' => $media,
                ],
                'caption' => $this->textSlot('', true),
                'cta' => ['isHidden' => true, 'label' => '', 'action' => null],
            ],
        ];
    }

    /** @return array{isHidden: bool, text: string} */
    private function textSlot(string $text, bool $isHidden): array
    {
        return ['isHidden' => $isHidden, 'text' => $text];
    }
}
