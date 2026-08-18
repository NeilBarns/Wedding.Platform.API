<?php

namespace App\Actions\Media;

use App\Models\MediaAsset;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionRegistry;
use Illuminate\Support\Collection;

final class MediaAssetUsageChecker
{
    public function __construct(private readonly WebsiteSectionRegistry $sections) {}

    public function isUsed(MediaAsset $asset): bool
    {
        return $this->resolve(collect([$asset]))[$asset->id]->isInUse();
    }

    /** @param Collection<int, MediaAsset> $assets */
    public function attach(Collection $assets): void
    {
        $usage = $this->resolve($assets);
        $assets->each(fn (MediaAsset $asset) => $asset->setRelation('resolvedUsage', $usage[$asset->id]));
    }

    /**
     * @param  Collection<int, MediaAsset>  $assets
     * @return array<string, MediaAssetUsage>
     */
    public function resolve(Collection $assets): array
    {
        $usage = $assets->mapWithKeys(fn (MediaAsset $asset): array => [$asset->id => []])->all();
        if ($assets->isEmpty()) {
            return [];
        }

        $assetEvents = $assets->mapWithKeys(fn (MediaAsset $asset): array => [$asset->event_id.':'.$asset->id => $asset->id]);
        $websiteSections = WebsiteSection::query()
            ->join('websites', 'websites.id', '=', 'website_sections.website_id')
            ->whereIn('websites.event_id', $assets->pluck('event_id')->unique())
            ->orderBy('website_sections.sort_order')
            ->orderBy('website_sections.id')
            ->get(['website_sections.id', 'website_sections.type', 'website_sections.content', 'websites.event_id']);

        foreach ($websiteSections as $section) {
            $assetId = $section->content['media']['assetId'] ?? null;
            $resolvedAssetId = is_string($assetId) ? $assetEvents->get($section->event_id.':'.$assetId) : null;
            if ($resolvedAssetId === null) {
                continue;
            }

            $usage[$resolvedAssetId][] = [
                'sectionId' => $section->id,
                'type' => $section->type,
                'displayName' => $this->sections->get($section->type)?->displayName ?? $section->type,
            ];
        }

        return collect($usage)->map(fn (array $sections): MediaAssetUsage => new MediaAssetUsage($sections))->all();
    }
}
