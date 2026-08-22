<?php

namespace App\Website;

final class StoryContentNormalizer
{
    /**
     * @param  array<string, mixed>  $content
     * @return array{heading: string, intro: string|null, blocks: list<array<string, mixed>>}
     */
    public function normalize(string $sectionId, array $content): array
    {
        if (array_key_exists('blocks', $content)) {
            return [
                'heading' => (string) ($content['heading'] ?? ''),
                'intro' => isset($content['intro']) ? (string) $content['intro'] : null,
                'blocks' => is_array($content['blocks']) ? array_values($content['blocks']) : [],
            ];
        }

        $body = (string) ($content['body'] ?? '');
        $media = $content['media'] ?? null;
        $hasLegacyBlock = trim($body) !== '' || is_array($media);

        return [
            'heading' => (string) ($content['heading'] ?? ''),
            'intro' => null,
            'blocks' => $hasLegacyBlock ? [array_filter([
                'id' => 'story-legacy-'.$sectionId,
                'heading' => null,
                'body' => $body,
                'media' => is_array($media) ? $media : null,
            ], fn (mixed $value, string $key): bool => $key !== 'media' || $value !== null, ARRAY_FILTER_USE_BOTH)] : [],
        ];
    }
}
