<?php

namespace App\Actions\Websites;

use App\Exceptions\IncompatibleWebsiteTemplate;
use App\Exceptions\UnknownWebsiteTemplate;
use App\Models\Website;
use App\Website\WebsiteTemplateRegistry;

final class AssignWebsiteTemplate
{
    public function __construct(private readonly WebsiteTemplateRegistry $registry) {}

    public function handle(Website $website, string $templateKey): Website
    {
        $definition = $this->registry->get($templateKey);

        if ($definition === null) {
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

        $website->template_key = $definition->key;
        $website->save();

        return $website;
    }
}
