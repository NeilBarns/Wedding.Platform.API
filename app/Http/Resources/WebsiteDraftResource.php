<?php

namespace App\Http\Resources;

use App\Models\MediaAsset;
use App\Website\WebsiteSectionMediaReferences;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $template = app(WebsiteTemplateRegistry::class)->get($this->template_key);

        return [
            'id' => $this->id,
            'eventId' => $this->event_id,
            'name' => $this->name,
            'templateKey' => $this->template_key,
            'designSettings' => $this->design_settings,
            'template' => $template === null ? null : [
                'key' => $template->key,
                'displayName' => $template->displayName,
                'designOptions' => $template->designOptions,
            ],
            'sections' => WebsiteSectionResource::collection($this->whenLoaded('sections')),
            'media' => (object) $this->resolvedMedia(),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function resolvedMedia(): array
    {
        if (! $this->relationLoaded('sections')) {
            return [];
        }
        $references = app(WebsiteSectionMediaReferences::class);
        $ids = $this->sections->flatMap(fn ($section) => $references->extract($section->type, $section->content))
            ->pluck('assetId')->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return MediaAsset::query()->where('event_id', $this->event_id)->whereKey($ids)->with('variants')->get()
            ->mapWithKeys(function (MediaAsset $asset): array {
                $web = $asset->variants->firstWhere('variant_key', 'web');
                if ($web === null) {
                    return [];
                }

                return [$asset->id => [
                    'id' => $asset->id,
                    'originalFilename' => $asset->original_filename,
                    'width' => $asset->width,
                    'height' => $asset->height,
                    'web' => [
                        'width' => $web->width,
                        'height' => $web->height,
                        'url' => route('events.media.variants.show', ['event' => $this->event_id, 'asset' => $asset->id, 'variant' => 'web']),
                    ],
                ]];
            })->all();
    }
}
