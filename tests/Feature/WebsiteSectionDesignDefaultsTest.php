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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebsiteSectionDesignDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_historical_sections_resolve_defaults_without_read_time_mutation(): void
    {
        [$event, $owner, $project] = $this->project();
        $story = $project->sections()->where('type', 'story')->sole();
        $rawAppearance = DB::table('website_sections')->where('id', $story->id)->value('appearance');
        $updatedAt = $story->updated_at;

        $response = $this->actingAs($owner)->getJson($this->base($event, $project))->assertOk();
        $index = $this->sectionIndex($response->json('data.sections'), 'story');
        $response->assertJsonPath('data.schemaVersion', 5)
            ->assertJsonPath("data.sections.{$index}.designDefaults", [])
            ->assertJsonPath("data.sections.{$index}.resolvedDesignContext.headingFontId", 'editorial-serif')
            ->assertJsonPath("data.sections.{$index}.resolvedDesignContext.bodyFontId", 'modern-sans')
            ->assertJsonPath("data.sections.{$index}.resolvedDesignContext.headingColorId", 'terracotta-text');

        $this->assertSame($rawAppearance, DB::table('website_sections')->where('id', $story->id)->value('appearance'));
        $this->assertEquals($updatedAt, $story->refresh()->updated_at);
    }

    public function test_story_accepts_all_roles_and_storage_preserves_unrelated_section_state(): void
    {
        [$event, $owner, $project] = $this->project();
        $story = $project->sections()->where('type', 'story')->sole();
        $appearance = $story->appearance;
        $appearance['responsive'] = ['mobile' => ['headingAlignment' => 'left']];
        $story->update(['appearance' => $appearance]);
        $before = $story->only(['id', 'website_id', 'type', 'content', 'sort_order', 'is_enabled', 'created_at']);
        $defaults = [
            'headingFontId' => 'romantic-serif',
            'bodyFontId' => 'classic-serif',
            'headingColorId' => 'olive-accent',
            'bodyColorId' => 'olive-text',
            'accentColorId' => 'terracotta-accent',
        ];

        $response = $this->actingAs($owner)->putJson($this->defaultsUrl($event, $project, $story->id), [
            'designDefaults' => $defaults,
        ])->assertOk();
        $index = $this->sectionIndex($response->json('data.sections'), 'story');
        $response->assertJsonPath("data.sections.{$index}.designDefaults", $defaults)
            ->assertJsonPath("data.sections.{$index}.resolvedDesignContext.headingFontId", 'romantic-serif')
            ->assertJsonPath("data.sections.{$index}.resolvedDesignContext.accentColorId", 'terracotta-accent');

        $story->refresh();
        $this->assertSame($defaults, $story->appearance['designDefaults']);
        $this->assertEquals($before, $story->only(array_keys($before)));
        $this->assertSame(['mobile' => ['headingAlignment' => 'left']], $story->appearance['responsive']);
    }

    public function test_section_role_template_and_exact_shape_validation_reject_invalid_intent(): void
    {
        [$event, $owner, $project] = $this->project();
        $sections = $project->sections()->get()->keyBy('type');
        $cases = [
            ['gallery', ['bodyFontId' => 'classic-serif']],
            ['gallery', ['bodyColorId' => 'terracotta-text']],
            ['gallery', ['accentColorId' => 'terracotta-accent']],
            ['dressCode', ['accentColorId' => 'terracotta-accent']],
            ['faq', ['accentColorId' => 'terracotta-accent']],
            ['story', ['headingFontId' => 'fashion-serif']],
            ['story', ['bodyFontId' => 'editorial-serif']],
            ['story', ['headingColorId' => 'plum-text']],
            ['story', ['headingColorId' => '#123456']],
            ['story', ['headingColorId' => 'missing-color']],
            ['story', ['unknownRole' => 'terracotta-text']],
            ['story', ['headingColorId' => null]],
        ];

        foreach ($cases as [$type, $defaults]) {
            $section = $sections[$type];
            $this->actingAs($owner)->putJson($this->defaultsUrl($event, $project, $section->id), [
                'designDefaults' => $defaults,
            ])->assertUnprocessable();
            $this->assertArrayNotHasKey('designDefaults', $section->refresh()->appearance);
        }
    }

    public function test_sparse_replacement_resets_one_or_all_overrides_and_uses_an_empty_json_object(): void
    {
        [$event, $owner, $project] = $this->project();
        $story = $project->sections()->where('type', 'story')->sole();
        $url = $this->defaultsUrl($event, $project, $story->id);

        $this->actingAs($owner)->putJson($url, ['designDefaults' => [
            'headingFontId' => 'romantic-serif',
            'bodyFontId' => 'classic-serif',
        ]])->assertOk();
        $this->actingAs($owner)->putJson($url, ['designDefaults' => [
            'bodyFontId' => 'classic-serif',
        ]])->assertOk()->assertJsonMissingPath('data.sections.2.designDefaults.headingFontId');
        $this->assertSame(['bodyFontId' => 'classic-serif'], $story->refresh()->appearance['designDefaults']);

        $this->actingAs($owner)->putJson($url, ['designDefaults' => (object) []])
            ->assertOk()->assertJsonPath('data.sections.2.designDefaults', []);
        $this->assertSame([], $story->refresh()->appearance['designDefaults']);
        $this->assertStringContainsString('"designDefaults":{}', DB::table('website_sections')->where('id', $story->id)->value('appearance'));
    }

    public function test_inheritance_is_dynamic_and_explicit_section_values_win(): void
    {
        [$event, $owner, $project] = $this->project();
        $story = $project->sections()->where('type', 'story')->sole();
        $base = $this->base($event, $project);
        $index = 2;

        $this->actingAs($owner)->getJson($base)->assertJsonPath("data.sections.{$index}.resolvedDesignContext.bodyFontId", 'modern-sans');
        $settings = [...$project->design_settings, 'fontSet' => 'romantic'];
        $this->actingAs($owner)->putJson("{$base}/design", ['designSettings' => $settings])
            ->assertOk()->assertJsonPath("data.sections.{$index}.resolvedDesignContext.bodyFontId", 'classic-serif');
        $this->assertArrayNotHasKey('designDefaults', $story->refresh()->appearance);

        $this->actingAs($owner)->putJson($this->defaultsUrl($event, $project, $story->id), [
            'designDefaults' => ['bodyFontId' => 'modern-sans'],
        ])->assertOk()->assertJsonPath("data.sections.{$index}.resolvedDesignContext.bodyFontId", 'modern-sans');
        $this->assertSame('romantic', $project->refresh()->design_settings['fontSet']);
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

    public function test_mutation_is_authorized_scoped_and_legacy_route_compatible(): void
    {
        [$event, $owner, $project] = $this->project();
        [$otherEvent, , $otherProject] = $this->project();
        $story = $project->sections()->where('type', 'story')->sole();
        $otherStory = $otherProject->sections()->where('type', 'story')->sole();
        $payload = ['designDefaults' => ['headingFontId' => 'romantic-serif']];
        $url = $this->defaultsUrl($event, $project, $story->id);

        $this->putJson($url, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->putJson($url, $payload)->assertForbidden();
        $this->actingAs($owner)->putJson($this->defaultsUrl($event, $project, $otherStory->id), $payload)->assertNotFound();
        $this->actingAs($owner)->putJson(
            "/api/events/{$otherEvent->id}/websites/{$project->id}/sections/{$story->id}/design-defaults",
            $payload,
        )->assertForbidden();

        $this->actingAs($owner)->putJson(
            "/api/events/{$event->id}/website/sections/{$story->id}/design-defaults",
            $payload,
        )->assertOk()->assertJsonPath('data.sections.2.designDefaults.headingFontId', 'romantic-serif');
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
