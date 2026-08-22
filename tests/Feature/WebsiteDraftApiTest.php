<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Enums\EventMembershipRole;
use App\Models\Event;
use App\Models\EventMembership;
use App\Models\User;
use App\Models\WebsiteSection;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteDraftApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeaders([
            'Accept' => 'application/json',
            'Origin' => 'http://localhost',
        ]);
    }

    public function test_draft_get_uses_event_authorization_for_owner_admin_and_super_admin(): void
    {
        [$event, $owner] = $this->createEvent();
        $admin = User::factory()->create();
        $unrelated = User::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();
        EventMembership::factory()->for($event)->for($admin)->create(['role' => EventMembershipRole::Admin]);
        $url = "/api/events/{$event->id}/website";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($owner)->getJson($url)->assertOk();
        $this->actingAs($admin)->getJson($url)->assertOk();
        $this->actingAs($unrelated)->getJson($url)->assertForbidden();
        $this->actingAs($superAdmin)->getJson($url)->assertOk();
    }

    public function test_draft_get_returns_registry_metadata_and_persisted_order(): void
    {
        [$event, $owner] = $this->createEvent();
        $website = $event->website;
        $hero = $website->sections()->where('type', 'hero')->sole();
        $story = $website->sections()->where('type', 'story')->sole();
        $hero->update(['sort_order' => 70, 'is_enabled' => false, 'content' => [
            'headline' => 'A heading',
            'subheadline' => 'A subheading',
        ]]);
        $story->update(['sort_order' => 5]);

        $response = $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk();
        $heroPayload = collect($response->json('data.sections'))->firstWhere('id', $hero->id);

        $response->assertJsonPath('data.id', $website->id)
            ->assertJsonPath('data.eventId', $event->id)
            ->assertJsonPath('data.templateKey', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)
            ->assertJsonPath('data.template.displayName', 'Classic Filipiniana')
            ->assertJsonPath('data.sections.0.id', $story->id)
            ->assertJsonPath('data.sections.0.displayName', 'Story')
            ->assertJsonPath('data.sections.0.content.blocks', [])
            ->assertJsonPath('data.sections.0.mediaCapability.mode', 'multiple')
            ->assertJsonCount(10, 'data.sections');
        $this->assertSame('Hero', $heroPayload['displayName']);
        $this->assertFalse($heroPayload['isEnabled']);
        $this->assertSame('A heading', $heroPayload['content']['headline']);
    }

    public function test_canonical_section_content_contracts_accept_valid_draft_payloads(): void
    {
        [$event, $owner] = $this->createEvent();
        $payloads = [
            'hero' => ['headline' => '', 'subheadline' => 'Together'],
            'date' => ['heading' => '', 'description' => 'Save the date'],
            'story' => ['heading' => 'Our Story', 'intro' => null, 'blocks' => [[
                'id' => 'story-one', 'heading' => null, 'body' => 'Plain text', 'media' => null,
            ]]],
            'schedule' => ['heading' => '', 'items' => [[
                'time' => '3:00 PM', 'title' => 'Ceremony', 'description' => '',
            ]]],
            'venue' => ['heading' => '', 'name' => 'Venue', 'address' => '', 'description' => ''],
            'dressCode' => ['heading' => '', 'description' => 'Formal'],
            'people' => ['heading' => 'Wedding Party', 'groups' => []],
            'gallery' => ['heading' => 'Gallery', 'items' => []],
            'faq' => ['heading' => '', 'items' => [['question' => 'When?', 'answer' => 'Soon']]],
            'rsvp' => ['heading' => '', 'description' => '', 'buttonLabel' => 'Respond'],
        ];

        foreach ($payloads as $type => $content) {
            $section = $event->website->sections()->where('type', $type)->sole();
            $this->actingAs($owner)
                ->putJson("/api/events/{$event->id}/website/sections/{$section->id}", ['content' => $content])
                ->assertOk()
                ->assertJsonPath(
                    'data.sections.'.array_search($section->id, $event->website->sections()->pluck('id')->all(), true).'.content',
                    $content,
                );
            $this->assertSame($content, $section->refresh()->content);
        }
    }

    public function test_story_blocks_round_trip_in_order_and_reject_invalid_structures(): void
    {
        [$event, $owner] = $this->createEvent();
        $story = $event->website->sections()->where('type', 'story')->sole();
        $url = "/api/events/{$event->id}/website/sections/{$story->id}";
        $blocks = [
            ['id' => 'first', 'heading' => null, 'body' => 'First chapter', 'media' => null],
            ['id' => 'second', 'heading' => 'The proposal', 'body' => 'Second chapter', 'media' => null],
        ];
        $content = ['heading' => 'Our Story', 'intro' => 'How it began', 'blocks' => $blocks];

        $this->actingAs($owner)->putJson($url, ['content' => $content])->assertOk()
            ->assertJsonPath('data.sections.2.content.blocks.0.id', 'first')
            ->assertJsonPath('data.sections.2.content.blocks.1.id', 'second');
        $this->assertSame($content, $story->refresh()->content);
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/template", [
            'templateKey' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1,
        ])->assertOk();
        $this->assertSame($content, $story->refresh()->content);

        $duplicate = [...$content, 'blocks' => [$blocks[0], [...$blocks[1], 'id' => 'first']]];
        $this->actingAs($owner)->putJson($url, ['content' => $duplicate])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['content' => [...$content, 'blocks' => array_fill(0, 21, $blocks[0])]])
            ->assertUnprocessable()->assertJsonValidationErrors('content.blocks');
        $this->actingAs($owner)->putJson($url, ['content' => [...$content, 'unexpected' => true]])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['content' => [...$content, 'blocks' => [['id' => 'broken', 'body' => [], 'media' => null]]]])->assertUnprocessable();
    }

    public function test_historical_story_content_is_read_as_one_stable_block_without_mutating_storage(): void
    {
        [$event, $owner] = $this->createEvent();
        $story = $event->website->sections()->where('type', 'story')->sole();
        $legacy = ['heading' => 'Our Story', 'body' => 'The original narrative'];
        $story->update(['content' => $legacy]);

        $first = $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk();
        $second = $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk();
        $firstStory = collect($first->json('data.sections'))->firstWhere('id', $story->id);
        $secondStory = collect($second->json('data.sections'))->firstWhere('id', $story->id);

        $this->assertSame('story-legacy-'.$story->id, $firstStory['content']['blocks'][0]['id']);
        $this->assertSame('The original narrative', $firstStory['content']['blocks'][0]['body']);
        $this->assertSame($firstStory['content'], $secondStory['content']);
        $this->assertSame($legacy, $story->refresh()->content);
    }

    public function test_content_update_rejects_unknown_keys_wrong_types_and_event_or_presentation_data(): void
    {
        [$event, $owner] = $this->createEvent();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $date = $event->website->sections()->where('type', 'date')->sole();
        $schedule = $event->website->sections()->where('type', 'schedule')->sole();

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", [
            'content' => ['headline' => 'Hi', 'subheadline' => '', 'backgroundColor' => '#fff'],
        ])->assertUnprocessable()->assertJsonValidationErrors('content');

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$date->id}", [
            'content' => ['heading' => '', 'description' => '', 'eventDate' => '2027-01-01'],
        ])->assertUnprocessable()->assertJsonValidationErrors('content');

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$schedule->id}", [
            'content' => ['heading' => '', 'items' => 'wrong'],
        ])->assertUnprocessable()->assertJsonValidationErrors('content.items');
    }

    public function test_gallery_rejects_client_created_media_items_and_unknown_sections_are_not_editable(): void
    {
        [$event, $owner] = $this->createEvent();
        $gallery = $event->website->sections()->where('type', 'gallery')->sole();
        $legacy = WebsiteSection::factory()->for($event->website)->forType('customLegacySection')->create();

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$gallery->id}", [
            'content' => ['heading' => '', 'items' => [['url' => 'https://example.test/image.jpg']]],
        ])->assertUnprocessable()->assertJsonValidationErrors('content.items');

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$legacy->id}", [
            'content' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('content');
    }

    public function test_content_update_changes_only_target_content(): void
    {
        [$event, $owner] = $this->createEvent();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $story = $event->website->sections()->where('type', 'story')->sole();
        $original = [
            'type' => $hero->type,
            'sort_order' => $hero->sort_order,
            'is_enabled' => $hero->is_enabled,
            'template_key' => $event->website->template_key,
            'story_content' => $story->content,
        ];

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", [
            'content' => ['headline' => 'Changed', 'subheadline' => 'Only content'],
            'type' => 'story', 'sortOrder' => 999, 'isEnabled' => false,
        ])->assertOk();

        $hero->refresh();
        $this->assertSame($original['type'], $hero->type);
        $this->assertSame($original['sort_order'], $hero->sort_order);
        $this->assertSame($original['is_enabled'], $hero->is_enabled);
        $this->assertSame($original['template_key'], $event->website->refresh()->template_key);
        $this->assertSame($original['story_content'], $story->refresh()->content);
    }

    public function test_template_update_uses_domain_validation_and_preserves_sections(): void
    {
        [$event, $owner] = $this->createEvent();
        $before = $event->website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all();
        $url = "/api/events/{$event->id}/website/template";

        $this->actingAs($owner)->putJson($url, ['templateKey' => 'unknown-template'])
            ->assertUnprocessable()->assertJsonValidationErrors('templateKey');
        $this->actingAs($owner)->putJson($url, [
            'templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
        ])->assertOk()->assertJsonPath('data.templateKey', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);

        $this->assertSame($before, $event->website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all());
    }

    public function test_enable_disable_preserves_content_and_checks_template_capability(): void
    {
        [$event, $owner] = $this->createEvent();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $hero->update(['content' => ['headline' => 'Keep', 'subheadline' => 'Me']]);
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/enabled";

        $this->actingAs($owner)->putJson($url, ['isEnabled' => false])
            ->assertOk()->assertJsonPath('data.sections.0.isEnabled', false);
        $this->assertSame(['headline' => 'Keep', 'subheadline' => 'Me'], $hero->refresh()->content);
        $this->actingAs($owner)->putJson($url, ['isEnabled' => true])->assertOk();

        $legacy = WebsiteSection::factory()->for($event->website)->forType('customLegacySection')->disabled()->create();
        $this->actingAs($owner)->putJson(
            "/api/events/{$event->id}/website/sections/{$legacy->id}/enabled",
            ['isEnabled' => true],
        )->assertUnprocessable()->assertJsonValidationErrors('isEnabled');
        $this->assertFalse($legacy->refresh()->is_enabled);
    }

    public function test_reorder_requires_exact_set_and_normalizes_dense_order(): void
    {
        [$event, $owner] = $this->createEvent();
        $sections = $event->website->sections()->get();
        $reversed = $sections->pluck('id')->reverse()->values()->all();
        $url = "/api/events/{$event->id}/website/sections/order";

        $this->actingAs($owner)->putJson($url, ['sectionIds' => $reversed])
            ->assertOk()->assertJsonPath('data.sections.0.id', $reversed[0]);
        $this->assertSame(range(10, 100, 10), $event->website->sections()->pluck('sort_order')->all());

        $this->actingAs($owner)->putJson($url, ['sectionIds' => array_slice($reversed, 1)])
            ->assertUnprocessable()->assertJsonValidationErrors('sectionIds');
        $this->actingAs($owner)->putJson($url, ['sectionIds' => [$reversed[0], $reversed[0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('sectionIds.1');

        [$otherEvent] = $this->createEvent();
        $foreignId = $otherEvent->website->sections()->value('id');
        $extra = $reversed;
        $extra[0] = $foreignId;
        $this->actingAs($owner)->putJson($url, ['sectionIds' => $extra])
            ->assertUnprocessable()->assertJsonValidationErrors('sectionIds');
    }

    public function test_cross_event_section_id_returns_not_found_and_unrelated_mutation_is_forbidden(): void
    {
        [$event, $owner] = $this->createEvent();
        [$otherEvent] = $this->createEvent();
        $foreignSection = $otherEvent->website->sections()->first();
        $content = $foreignSection->content;

        $this->actingAs($owner)->putJson(
            "/api/events/{$event->id}/website/sections/{$foreignSection->id}",
            ['content' => ['headline' => 'Attack', 'subheadline' => '']],
        )->assertNotFound();
        $this->assertSame($content, $foreignSection->refresh()->content);

        $unrelated = User::factory()->create();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $this->actingAs($unrelated)->putJson(
            "/api/events/{$event->id}/website/sections/{$hero->id}/enabled",
            ['isEnabled' => false],
        )->assertForbidden();
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
