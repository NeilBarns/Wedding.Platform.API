<?php

namespace App\Actions\Websites;

use App\Exceptions\IncompatibleWebsiteTemplate;
use App\Exceptions\UnknownWebsiteTemplate;
use App\Models\Website;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Support\Facades\DB;

final class AssignWebsiteTemplate
{
    public function __construct(private readonly WebsiteTemplateRegistry $registry) {}

    public function handle(Website $website, string $templateKey): Website
    {
        $definition = $this->registry->get($templateKey);

        if ($definition === null || ! $definition->enabled) {
            throw new UnknownWebsiteTemplate($templateKey);
        }

        $website->loadMissing('event');

        if (! $definition->supportsEventType($website->event->type)) {
            throw IncompatibleWebsiteTemplate::forEventType($templateKey, $website->event->type->value);
        }

        $unsupportedSections = $website->sections()
            ->where('is_enabled', true)
            ->pluck('type')
            ->reject(fn (string $sectionType): bool => $definition->supportsSection($sectionType))
            ->values()
            ->all();

        if ($unsupportedSections !== []) {
            throw IncompatibleWebsiteTemplate::forSections($templateKey, $unsupportedSections);
        }

        DB::transaction(function () use ($website, $definition): void {
            $website->template_key = $definition->key;
            $website->design_settings = $definition->normalizeDesignSettings($website->design_settings ?? []);
            $website->save();

            $website->sections()->get()->each(function ($section) use ($definition): void {
                $normalized = $definition->normalizeSectionAppearance($section->type, $section->appearance ?? []);
                if ($normalized !== $section->appearance) {
                    $section->update(['appearance' => $normalized]);
                }
            });
        });

        return $website;
    }
}
