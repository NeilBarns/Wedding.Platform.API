<?php

namespace App\Website;

final class WebsiteSectionMediaReferences
{
    /**
     * @param  array<string, mixed>  $content
     * @return list<array{assetId: string, context?: array<string, string>}>
     */
    public function extract(string $sectionType, array $content): array
    {
        $references = [];
        $sectionAssetId = $content['media']['assetId'] ?? null;
        if (is_string($sectionAssetId)) {
            $references[] = ['assetId' => $sectionAssetId];
        }

        if ($sectionType === 'story') {
            foreach ($content['blocks'] ?? [] as $block) {
                $assetId = $block['media']['assetId'] ?? null;
                if (! is_string($assetId)) {
                    continue;
                }
                $references[] = [
                    'assetId' => $assetId,
                    'context' => [
                        'blockId' => (string) ($block['id'] ?? ''),
                        'blockHeading' => (string) ($block['heading'] ?? ''),
                    ],
                ];
            }
        }

        if ($sectionType !== 'people') {
            return $references;
        }

        foreach ($content['groups'] ?? [] as $group) {
            foreach ($group['people'] ?? [] as $person) {
                $assetId = $person['media']['assetId'] ?? null;
                if (! is_string($assetId)) {
                    continue;
                }
                $references[] = [
                    'assetId' => $assetId,
                    'context' => [
                        'groupId' => (string) ($group['id'] ?? ''),
                        'groupName' => (string) ($group['name'] ?? ''),
                        'personId' => (string) ($person['id'] ?? ''),
                        'personName' => (string) ($person['name'] ?? ''),
                    ],
                ];
            }
        }

        return $references;
    }
}
