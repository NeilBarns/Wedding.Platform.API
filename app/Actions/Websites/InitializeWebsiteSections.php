<?php

namespace App\Actions\Websites;

use App\Models\Website;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InitializeWebsiteSections
{
    public function __construct(
        private readonly WebsiteSectionRegistry $registry,
        private readonly WebsiteTemplateRegistry $templates,
    ) {}

    public function handle(Website $website): void
    {
        DB::transaction(function () use ($website): void {
            $website->loadMissing('event');
            $definitions = $this->registry->defaultCompositionFor($website->event->type);
            $template = $this->templates->get($website->template_key);
            $existingTypes = $website->sections()->pluck('type')->all();
            $timestamp = now();
            $rows = [];

            foreach ($definitions as $definition) {
                if (in_array($definition->key, $existingTypes, true)) {
                    continue;
                }

                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'website_id' => $website->getKey(),
                    'type' => $definition->key,
                    'sort_order' => $definition->defaultOrder,
                    'is_enabled' => $definition->defaultEnabled,
                    'content' => json_encode($definition->defaultContent, JSON_THROW_ON_ERROR),
                    'appearance' => json_encode(
                        $template?->appearanceDefaultsFor($definition->key) ?? WebsiteSectionAppearance::DEFAULT,
                        JSON_THROW_ON_ERROR,
                    ),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            if ($rows !== []) {
                DB::table('website_sections')->insertOrIgnore($rows);
            }
        });
    }
}
