<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\InitializeWebsiteSections;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteSectionDesignDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_presentation_narrowing_preserves_hidden_intent_and_other_mutations(): void
    {
        [$event, $owner, $project] = $this->project(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $hero = $project->sections()->where('type', 'hero')->sole();
        $base = $this->base($event, $project);
        $url = $this->defaultsUrl($event, $project, $hero->id);
        $appearance = $hero->appearance;
        $appearance['presentation'] = 'editorial';

        $this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}/appearance", compact('appearance'))->assertOk();
        $this->actingAs($owner)->putJson($url, ['designDefaults' => ['headingColorId' => 'ink-accent']])
            ->assertOk()->assertJsonPath('data.sections.0.resolvedDesignContext.headingColorId', 'ink-accent');

        $appearance['presentation'] = 'immersive';
        $this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}/appearance", compact('appearance'))
            ->assertOk()
            ->assertJsonPath('data.sections.0.designDefaults.headingColorId', 'ink-accent')
            ->assertJsonPath('data.sections.0.resolvedDesignContext.headingColorId', 'ink-text');
        $this->assertSame('ink-accent', $hero->refresh()->appearance['designDefaults']['headingColorId']);

        $this->actingAs($owner)->putJson($url, ['designDefaults' => [
            'headingColorId' => 'ink-accent',
            'headingFontId' => 'fashion-serif',
        ]])->assertOk()->assertJsonPath('data.sections.0.resolvedDesignContext.headingFontId', 'fashion-serif');

        $this->actingAs($owner)->putJson($url, ['designDefaults' => ['headingColorId' => 'plum-accent']])->assertUnprocessable();
        $this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}", ['content' => [
            'headline' => 'Still preserved',
            'subheadline' => '',
        ]])->assertOk()->assertJsonPath('data.sections.0.designDefaults.headingColorId', 'ink-accent');

        $appearance['presentation'] = 'editorial';
        $this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}/appearance", compact('appearance'))
            ->assertOk()->assertJsonPath('data.sections.0.resolvedDesignContext.headingColorId', 'ink-accent');
    }

    /** @return array{Event, User, Website} */
    private function project(string $templateKey = WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1): array
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'A Wedding']);
        $template = app(WebsiteTemplateRegistry::class)->get($templateKey);
        $project = Website::factory()->for($event)->create([
            'template_key' => $templateKey,
            'design_settings' => app(WebsiteCapabilityResolver::class)->canonicalDesignDefaults($template),
        ]);
        app(InitializeWebsiteSections::class)->handle($project);

        return [$event, $owner, $project->refresh()];
    }

    private function base(Event $event, Website $project): string
    {
        return "/api/events/{$event->id}/websites/{$project->id}";
    }

    private function defaultsUrl(Event $event, Website $project, string $sectionId): string
    {
        return $this->base($event, $project)."/sections/{$sectionId}/design-defaults";
    }

    /** @param list<array<string, mixed>> $sections */
    private function sectionIndex(array $sections, string $type): int
    {
        return array_search($type, array_column($sections, 'type'), true);
    }
}
