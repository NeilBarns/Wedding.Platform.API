<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\AssignWebsiteTemplate;
use App\Enums\EventType;
use App\Exceptions\UnknownWebsiteTemplate;
use App\Models\Event;
use App\Models\User;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteTemplateDefinition;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteTemplateSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_compatible_templates_are_listed_with_current_selection_and_product_metadata(): void
    {
        [$event, $owner] = $this->createEvent();

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website/templates")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.key', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)
            ->assertJsonPath('data.0.displayName', 'Classic Filipiniana')
            ->assertJsonPath('data.0.isSelected', true)
            ->assertJsonPath('data.0.styleTags.0', 'Classic')
            ->assertJsonMissingPath('data.0.designOptions');

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website/templates")
            ->assertJsonPath('data.1.key', WebsiteTemplateRegistry::MODERN_EDITORIAL_V1)
            ->assertJsonPath('data.1.displayName', 'Modern Editorial')
            ->assertJsonPath('data.1.styleTags', ['Modern', 'Editorial', 'Minimal'])
            ->assertJsonPath('data.1.isSelected', false);
    }

    public function test_switching_between_production_templates_normalizes_design_and_preserves_sections(): void
    {
        [$event, $owner] = $this->createEvent();
        $website = $event->website;
        $website->update(['design_settings' => ['colorTheme' => 'olive', 'fontSet' => 'romantic', 'artStyle' => 'botanical']]);
        $story = $website->sections()->where('type', 'story')->sole();
        $story->update([
            'content' => ['heading' => 'How we met', 'body' => "First line\nSecond line"],
            'sort_order' => 7,
            'is_enabled' => false,
            'appearance' => ['headingAlignment' => 'right', 'bodyAlignment' => 'left', 'backgroundTreatment' => 'accent', 'emphasis' => 'featured'],
        ]);
        $before = $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all();

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/template", [
            'templateKey' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1,
        ])->assertOk()
            ->assertJsonPath('data.templateKey', WebsiteTemplateRegistry::MODERN_EDITORIAL_V1)
            ->assertJsonPath('data.designSettings.colorTheme', 'ink')
            ->assertJsonPath('data.designSettings.fontSet', 'editorial')
            ->assertJsonPath('data.designSettings.artStyle', 'clean');

        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all());
        $this->assertSame(['headingAlignment' => 'right', 'bodyAlignment' => 'left', 'backgroundTreatment' => 'accent', 'emphasis' => 'featured'], $story->refresh()->appearance);

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website/templates")
            ->assertJsonPath('data.0.isSelected', false)
            ->assertJsonPath('data.1.isSelected', true);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/template", [
            'templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
        ])->assertOk()->assertJsonPath('data.templateKey', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);

        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all());
    }

    public function test_template_listing_uses_event_authorization_and_event_scope(): void
    {
        [$event, $owner] = $this->createEvent();
        [$otherEvent, $otherOwner] = $this->createEvent();
        $url = "/api/events/{$event->id}/website/templates";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($otherOwner)->getJson($url)->assertForbidden();
        $this->actingAs($owner)->getJson($url)->assertOk();
        $this->actingAs($otherOwner)->getJson("/api/events/{$otherEvent->id}/website/templates")->assertOk();
    }

    public function test_assignment_normalizes_design_and_appearance_without_mutating_semantic_sections(): void
    {
        [$event] = $this->createEvent();
        $website = $event->website;
        $website->update(['design_settings' => ['colorTheme' => 'olive', 'fontSet' => 'romantic', 'artStyle' => 'woven']]);
        $hero = $website->sections()->where('type', 'hero')->sole();
        $hero->update([
            'content' => ['headline' => 'Keep this', 'subheadline' => 'And this'],
            'sort_order' => 8,
            'is_enabled' => false,
            'appearance' => ['headingAlignment' => 'right', 'bodyAlignment' => 'right', 'backgroundTreatment' => 'accent', 'emphasis' => 'featured'],
        ]);
        $before = $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all();

        $target = $this->targetDefinition();
        $registry = new WebsiteTemplateRegistry([$target->key => $target]);
        (new AssignWebsiteTemplate($registry))->handle($website, $target->key);

        $this->assertSame($target->key, $website->refresh()->template_key);
        $this->assertSame(['colorTheme' => 'olive', 'fontSet' => 'modern', 'artStyle' => 'woven'], $website->design_settings);
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all());
        $this->assertSame([
            'headingAlignment' => 'inherit',
            'bodyAlignment' => 'right',
            'backgroundTreatment' => 'plain',
            'emphasis' => 'featured',
        ], $hero->refresh()->appearance);
    }

    public function test_disabled_template_is_rejected(): void
    {
        [$event] = $this->createEvent();
        $disabled = $this->targetDefinition(enabled: false);

        $this->expectException(UnknownWebsiteTemplate::class);
        (new AssignWebsiteTemplate(new WebsiteTemplateRegistry([$disabled->key => $disabled])))
            ->handle($event->website, $disabled->key);
    }

    private function targetDefinition(bool $enabled = true): WebsiteTemplateDefinition
    {
        $production = (new WebsiteTemplateRegistry)->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $appearance = WebsiteSectionAppearance::OPTIONS;
        $appearance['headingAlignments'] = array_slice($appearance['headingAlignments'], 0, 2);
        $appearance['backgroundTreatments'] = [['key' => 'plain', 'displayName' => 'Plain']];

        return new WebsiteTemplateDefinition(
            key: 'test-target-v1',
            displayName: 'Test Target',
            description: 'Test-only Template.',
            styleTags: ['Test'],
            enabled: $enabled,
            supportedEventTypes: [EventType::Wedding],
            supportedSectionTypes: $production->supportedSectionTypes,
            designOptions: [
                'colorThemes' => [['key' => 'olive', 'displayName' => 'Olive']],
                'fontSets' => [['key' => 'modern', 'displayName' => 'Modern']],
                'artStyles' => [['key' => 'woven', 'displayName' => 'Woven']],
            ],
            defaultDesignSettings: ['colorTheme' => 'olive', 'fontSet' => 'modern', 'artStyle' => 'woven'],
            sectionAppearanceOptions: array_fill_keys($production->supportedSectionTypes, $appearance),
            sectionAppearanceDefaults: array_fill_keys($production->supportedSectionTypes, [
                'headingAlignment' => 'inherit',
                'bodyAlignment' => 'inherit',
                'backgroundTreatment' => 'plain',
                'emphasis' => 'inherit',
            ]),
        );
    }

    /** @return array{Event, User} */
    private function createEvent(): array
    {
        $owner = User::factory()->create();

        $event = app(CreateEvent::class)->handle($owner, ['name' => fake()->words(3, true)]);
        $this->initializeWebsite($event);

        return [$event->refresh(), $owner];
    }
}
