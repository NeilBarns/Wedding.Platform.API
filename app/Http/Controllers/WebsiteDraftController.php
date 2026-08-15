<?php

namespace App\Http\Controllers;

use App\Actions\Websites\AssignWebsiteTemplate;
use App\Actions\Websites\ReorderWebsiteSections;
use App\Actions\Websites\SetWebsiteSectionEnabled;
use App\Actions\Websites\UpdateWebsiteDesignSettings;
use App\Actions\Websites\UpdateWebsiteSectionAppearance;
use App\Actions\Websites\UpdateWebsiteSectionContent;
use App\Exceptions\IncompatibleWebsiteTemplate;
use App\Exceptions\UnknownWebsiteTemplate;
use App\Http\Requests\ReorderWebsiteSectionsRequest;
use App\Http\Requests\UpdateWebsiteDesignSettingsRequest;
use App\Http\Requests\UpdateWebsiteSectionAppearanceRequest;
use App\Http\Requests\UpdateWebsiteSectionContentRequest;
use App\Http\Requests\UpdateWebsiteSectionEnabledRequest;
use App\Http\Requests\UpdateWebsiteTemplateRequest;
use App\Http\Resources\WebsiteDraftResource;
use App\Models\Event;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WebsiteDraftController extends Controller
{
    public function show(string $event): WebsiteDraftResource
    {
        return $this->draft($this->authorizedEvent($event)->website()->firstOrFail());
    }

    public function templates(WebsiteTemplateRegistry $templates, string $event): JsonResponse
    {
        $website = $this->authorizedEvent($event)->website()->firstOrFail();
        $website->loadMissing('event');
        $sectionTypes = $website->sections()->where('is_enabled', true)->pluck('type');

        $compatible = array_filter(
            $templates->forEventType($website->event->type),
            fn ($template): bool => $templates->isCompatible($template->key, $website->event->type, $sectionTypes),
        );

        return response()->json(['data' => array_values(array_map(
            fn ($template): array => [
                'key' => $template->key,
                'displayName' => $template->displayName,
                'description' => $template->description,
                'styleTags' => $template->styleTags,
                'isSelected' => $template->key === $website->template_key,
            ],
            $compatible,
        ))]);
    }

    public function updateTemplate(
        UpdateWebsiteTemplateRequest $request,
        AssignWebsiteTemplate $assignTemplate,
        string $event,
    ): WebsiteDraftResource {
        $website = $this->authorizedEvent($event)->website()->firstOrFail();

        try {
            $assignTemplate->handle($website, $request->validated('templateKey'));
        } catch (UnknownWebsiteTemplate|IncompatibleWebsiteTemplate $exception) {
            throw ValidationException::withMessages(['templateKey' => $exception->getMessage()]);
        }

        return $this->draft($website);
    }

    public function updateDesign(
        UpdateWebsiteDesignSettingsRequest $request,
        UpdateWebsiteDesignSettings $updateDesign,
        string $event,
    ): WebsiteDraftResource {
        $website = $this->authorizedEvent($event)->website()->firstOrFail();
        $updateDesign->handle($website, $request->validated('designSettings'));

        return $this->draft($website);
    }

    public function updateSection(
        UpdateWebsiteSectionContentRequest $request,
        UpdateWebsiteSectionContent $updateContent,
        string $event,
        string $section,
    ): WebsiteDraftResource {
        $website = $this->authorizedEvent($event)->website()->firstOrFail();
        $sectionModel = $this->section($website, $section);
        $updateContent->handle($sectionModel, $request->validated('content'));

        return $this->draft($website);
    }

    public function updateSectionAppearance(
        UpdateWebsiteSectionAppearanceRequest $request,
        UpdateWebsiteSectionAppearance $updateAppearance,
        string $event,
        string $section,
    ): WebsiteDraftResource {
        $website = $this->authorizedEvent($event)->website()->firstOrFail();
        $sectionModel = $this->section($website, $section);
        $updateAppearance->handle($sectionModel, $request->validated('appearance'));

        return $this->draft($website);
    }

    public function updateSectionEnabled(
        UpdateWebsiteSectionEnabledRequest $request,
        SetWebsiteSectionEnabled $setEnabled,
        string $event,
        string $section,
    ): WebsiteDraftResource {
        $website = $this->authorizedEvent($event)->website()->firstOrFail();
        $sectionModel = $this->section($website, $section);

        try {
            $setEnabled->handle($sectionModel, $request->boolean('isEnabled'));
        } catch (UnknownWebsiteTemplate|IncompatibleWebsiteTemplate $exception) {
            throw ValidationException::withMessages(['isEnabled' => $exception->getMessage()]);
        }

        return $this->draft($website);
    }

    public function reorder(
        ReorderWebsiteSectionsRequest $request,
        ReorderWebsiteSections $reorderSections,
        string $event,
    ): WebsiteDraftResource {
        $website = $this->authorizedEvent($event)->website()->firstOrFail();
        $reorderSections->handle($website, $request->validated('sectionIds'));

        return $this->draft($website);
    }

    private function authorizedEvent(string $event): Event
    {
        $model = Event::query()->findOrFail($event);
        Gate::authorize('view', $model);

        return $model;
    }

    private function section(Website $website, string $section): WebsiteSection
    {
        return $website->sections()->findOrFail($section);
    }

    private function draft(Website $website): WebsiteDraftResource
    {
        return new WebsiteDraftResource($website->refresh()->load('sections.website'));
    }
}
