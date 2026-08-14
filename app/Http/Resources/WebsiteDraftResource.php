<?php

namespace App\Http\Resources;

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
            'templateKey' => $this->template_key,
            'designSettings' => $this->design_settings,
            'template' => $template === null ? null : [
                'key' => $template->key,
                'displayName' => $template->displayName,
                'designOptions' => $template->designOptions,
            ],
            'sections' => WebsiteSectionResource::collection($this->whenLoaded('sections')),
        ];
    }
}
