<?php

namespace App\Actions\Websites;

use App\Exceptions\IncompatibleWebsiteTemplate;
use App\Exceptions\UnknownWebsiteTemplate;
use App\Models\Event;
use App\Models\Website;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Support\Facades\DB;

final class CreateWebsiteProject
{
    public function __construct(
        private readonly WebsiteTemplateRegistry $templates,
        private readonly WebsiteSectionRegistry $sections,
        private readonly InitializeWebsiteSections $initializeSections,
    ) {}

    public function handle(Event $event, string $name, string $templateKey): Website
    {
        return DB::transaction(function () use ($event, $name, $templateKey): Website {
            $lockedEvent = Event::query()->lockForUpdate()->findOrFail($event->getKey());
            $template = $this->templates->get($templateKey);

            if ($template === null || ! $template->enabled) {
                throw new UnknownWebsiteTemplate($templateKey);
            }
            if (! $template->supportsEventType($lockedEvent->type)) {
                throw IncompatibleWebsiteTemplate::forEventType($templateKey, $lockedEvent->type->value);
            }

            $definitions = $this->sections->defaultCompositionFor($lockedEvent->type);
            $unsupported = array_values(array_filter(
                array_keys($definitions),
                fn (string $sectionType): bool => ! $template->supportsSection($sectionType),
            ));
            if ($unsupported !== []) {
                throw IncompatibleWebsiteTemplate::forSections($templateKey, $unsupported);
            }

            $website = $lockedEvent->websiteProjects()->create([
                'name' => trim($name),
                'template_key' => $template->key,
                'design_settings' => $template->defaultDesignSettings,
            ]);
            $this->initializeSections->handle($website);

            return $website;
        });
    }
}
