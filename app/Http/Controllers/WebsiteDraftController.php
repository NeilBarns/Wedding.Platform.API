<?php

namespace App\Http\Controllers;

use App\Actions\Websites\AssignWebsiteTemplate;
use App\Actions\Websites\CreateWebsiteProject;
use App\Actions\Websites\InitializeEventWebsite;
use App\Actions\Websites\ReorderWebsiteSections;
use App\Actions\Websites\SetWebsiteSectionEnabled;
use App\Actions\Websites\UpdateWebsiteDesignSettings;
use App\Actions\Websites\UpdateWebsiteSectionAppearance;
use App\Actions\Websites\UpdateWebsiteSectionContent;
use App\Exceptions\IncompatibleWebsiteTemplate;
use App\Exceptions\UnknownWebsiteTemplate;
use App\Exceptions\WebsiteAlreadyInitialized;
use App\Http\Requests\CreateWebsiteProjectRequest;
use App\Http\Requests\InitializeWebsiteRequest;
use App\Http\Requests\ReorderWebsiteSectionsRequest;
use App\Http\Requests\UpdateWebsiteDesignSettingsRequest;
use App\Http\Requests\UpdateWebsiteSectionAppearanceRequest;
use App\Http\Requests\UpdateWebsiteSectionContentRequest;
use App\Http\Requests\UpdateWebsiteSectionEnabledRequest;
use App\Http\Requests\UpdateWebsiteTemplateRequest;
use App\Http\Resources\WebsiteDraftResource;
use App\Http\Resources\WebsiteProjectResource;
use App\Models\Event;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionRegistry;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WebsiteDraftController extends Controller
{
    public function show(string $event): WebsiteDraftResource
    {
        return $this->draft($this->legacyWebsite($this->authorizedEvent($event)));
    }

    public function projects(string $event): AnonymousResourceCollection
    {
        $eventModel = $this->authorizedEvent($event);

        return WebsiteProjectResource::collection(
            $eventModel->websiteProjects()->orderBy('created_at')->orderBy('id')->get(),
        );
    }

    public function storeProject(
        CreateWebsiteProjectRequest $request,
        CreateWebsiteProject $createProject,
        string $event,
    ): WebsiteDraftResource|JsonResponse {
        $eventModel = $this->authorizedEvent($event);

        try {
            $website = $createProject->handle(
                $eventModel,
                $request->validated('name'),
                $request->validated('templateKey'),
            );
        } catch (UnknownWebsiteTemplate|IncompatibleWebsiteTemplate $exception) {
            throw ValidationException::withMessages(['templateKey' => $exception->getMessage()]);
        }

        return $this->draft($website)->response()->setStatusCode(201);
    }

    public function showProject(string $event, string $website): WebsiteDraftResource
    {
        $eventModel = $this->authorizedEvent($event);

        return $this->draft($this->website($eventModel, $website));
    }

    public function store(
        InitializeWebsiteRequest $request,
        InitializeEventWebsite $initializeWebsite,
        string $event,
    ): WebsiteDraftResource|JsonResponse {
        $eventModel = $this->authorizedEvent($event);

        try {
            $website = $initializeWebsite->handle($eventModel, $request->validated('templateKey'));
        } catch (WebsiteAlreadyInitialized $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (UnknownWebsiteTemplate|IncompatibleWebsiteTemplate $exception) {
            throw ValidationException::withMessages(['templateKey' => $exception->getMessage()]);
        }

        return $this->draft($website)->response()->setStatusCode(201);
    }

    public function templates(WebsiteTemplateRegistry $templates, WebsiteSectionRegistry $sections, string $event): JsonResponse
    {
        $eventModel = $this->authorizedEvent($event);

        return $this->templateList($templates, $sections, $eventModel, $this->legacyWebsiteOrNull($eventModel));
    }

    public function projectTemplates(
        WebsiteTemplateRegistry $templates,
        WebsiteSectionRegistry $sections,
        string $event,
        string $website,
    ): JsonResponse {
        $eventModel = $this->authorizedEvent($event);

        return $this->templateList($templates, $sections, $eventModel, $this->website($eventModel, $website));
    }

    private function templateList(
        WebsiteTemplateRegistry $templates,
        WebsiteSectionRegistry $sections,
        Event $event,
        ?Website $website,
    ): JsonResponse {
        $sectionTypes = $website
            ? $website->sections()->where('is_enabled', true)->pluck('type')
            : array_keys(array_filter(
                $sections->defaultCompositionFor($event->type),
                fn ($definition): bool => $definition->defaultEnabled,
            ));

        $compatible = array_filter(
            $templates->forEventType($event->type),
            fn ($template): bool => $templates->isCompatible($template->key, $event->type, $sectionTypes),
        );

        return response()->json(['data' => array_values(array_map(
            fn ($template): array => [
                'key' => $template->key,
                'displayName' => $template->displayName,
                'description' => $template->description,
                'styleTags' => $template->styleTags,
                'isSelected' => $website !== null && $template->key === $website->template_key,
            ],
            $compatible,
        ))]);
    }

    public function updateTemplate(
        UpdateWebsiteTemplateRequest $request,
        AssignWebsiteTemplate $assignTemplate,
        string $event,
    ): WebsiteDraftResource {
        $website = $this->legacyWebsite($this->authorizedEvent($event));

        return $this->applyTemplate($request, $assignTemplate, $website);
    }

    public function updateProjectTemplate(
        UpdateWebsiteTemplateRequest $request,
        AssignWebsiteTemplate $assignTemplate,
        string $event,
        string $website,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);

        return $this->applyTemplate($request, $assignTemplate, $this->website($eventModel, $website));
    }

    private function applyTemplate(
        UpdateWebsiteTemplateRequest $request,
        AssignWebsiteTemplate $assignTemplate,
        Website $website,
    ): WebsiteDraftResource {

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
        $website = $this->legacyWebsite($this->authorizedEvent($event));

        return $this->applyDesign($request, $updateDesign, $website);
    }

    public function updateProjectDesign(
        UpdateWebsiteDesignSettingsRequest $request,
        UpdateWebsiteDesignSettings $updateDesign,
        string $event,
        string $website,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);

        return $this->applyDesign($request, $updateDesign, $this->website($eventModel, $website));
    }

    private function applyDesign(
        UpdateWebsiteDesignSettingsRequest $request,
        UpdateWebsiteDesignSettings $updateDesign,
        Website $website,
    ): WebsiteDraftResource {
        $updateDesign->handle($website, $request->validated('designSettings'));

        return $this->draft($website);
    }

    public function updateSection(
        UpdateWebsiteSectionContentRequest $request,
        UpdateWebsiteSectionContent $updateContent,
        string $event,
        string $section,
    ): WebsiteDraftResource {
        $website = $this->legacyWebsite($this->authorizedEvent($event));

        return $this->applySectionContent($request, $updateContent, $website, $section);
    }

    public function updateProjectSection(
        UpdateWebsiteSectionContentRequest $request,
        UpdateWebsiteSectionContent $updateContent,
        string $event,
        string $website,
        string $section,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);

        return $this->applySectionContent($request, $updateContent, $this->website($eventModel, $website), $section);
    }

    private function applySectionContent(
        UpdateWebsiteSectionContentRequest $request,
        UpdateWebsiteSectionContent $updateContent,
        Website $website,
        string $section,
    ): WebsiteDraftResource {
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
        $website = $this->legacyWebsite($this->authorizedEvent($event));

        return $this->applySectionAppearance($request, $updateAppearance, $website, $section);
    }

    public function updateProjectSectionAppearance(
        UpdateWebsiteSectionAppearanceRequest $request,
        UpdateWebsiteSectionAppearance $updateAppearance,
        string $event,
        string $website,
        string $section,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);

        return $this->applySectionAppearance($request, $updateAppearance, $this->website($eventModel, $website), $section);
    }

    private function applySectionAppearance(
        UpdateWebsiteSectionAppearanceRequest $request,
        UpdateWebsiteSectionAppearance $updateAppearance,
        Website $website,
        string $section,
    ): WebsiteDraftResource {
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
        $website = $this->legacyWebsite($this->authorizedEvent($event));

        return $this->applySectionEnabled($request, $setEnabled, $website, $section);
    }

    public function updateProjectSectionEnabled(
        UpdateWebsiteSectionEnabledRequest $request,
        SetWebsiteSectionEnabled $setEnabled,
        string $event,
        string $website,
        string $section,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);

        return $this->applySectionEnabled($request, $setEnabled, $this->website($eventModel, $website), $section);
    }

    private function applySectionEnabled(
        UpdateWebsiteSectionEnabledRequest $request,
        SetWebsiteSectionEnabled $setEnabled,
        Website $website,
        string $section,
    ): WebsiteDraftResource {
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
        $website = $this->legacyWebsite($this->authorizedEvent($event));

        return $this->applyReorder($request, $reorderSections, $website);
    }

    public function reorderProjectSections(
        ReorderWebsiteSectionsRequest $request,
        ReorderWebsiteSections $reorderSections,
        string $event,
        string $website,
    ): WebsiteDraftResource {
        $eventModel = $this->authorizedEvent($event);

        return $this->applyReorder($request, $reorderSections, $this->website($eventModel, $website));
    }

    private function applyReorder(
        ReorderWebsiteSectionsRequest $request,
        ReorderWebsiteSections $reorderSections,
        Website $website,
    ): WebsiteDraftResource {
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

    private function legacyWebsite(Event $event): Website
    {
        return $this->legacyWebsiteOrNull($event)
            ?? throw (new ModelNotFoundException)->setModel(Website::class);
    }

    private function legacyWebsiteOrNull(Event $event): ?Website
    {
        $websites = $event->websiteProjects()->limit(2)->get();

        if ($websites->count() > 1) {
            abort(409, 'This Event has multiple Website Projects. Use a project-specific route.');
        }

        return $websites->first();
    }

    private function website(Event $event, string $website): Website
    {
        return $event->websiteProjects()->findOrFail($website);
    }

    private function draft(Website $website): WebsiteDraftResource
    {
        return new WebsiteDraftResource($website->refresh()->load('sections.website'));
    }
}
