<?php

namespace Tests\Feature;

use App\Actions\Websites\CreateWebsiteProject;
use App\Enums\EventMembershipRole;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteSectionMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media-test');
    }

    public function test_draft_without_referenced_media_serializes_an_empty_media_object(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $this->initializeWebsite($event);

        $response = $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk();
        $media = json_decode($response->getContent())->data->media;

        $this->assertInstanceOf(\stdClass::class, $media);
        $this->assertSame([], get_object_vars($media));
    }

    public function test_owner_assigns_event_image_with_focal_point_and_draft_resolves_only_referenced_media(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $hero = $website->sections()->where('type', 'hero')->firstOrFail();
        $asset = $this->assetFor($event);
        $unused = $this->assetFor($event);

        $response = $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", [
            'content' => ['headline' => 'Hello', 'subheadline' => 'World', 'media' => ['assetId' => $asset->id, 'focalPoint' => ['x' => 0.25, 'y' => 0.75]]],
        ]);

        $response->assertOk()->assertJsonPath("data.media.{$asset->id}.id", $asset->id)
            ->assertJsonPath("data.media.{$asset->id}.web.url", route('events.media.variants.show', ['event' => $event->id, 'asset' => $asset->id, 'variant' => 'web']))
            ->assertJsonMissingPath("data.media.{$unused->id}")
            ->assertJsonMissingPath("data.media.{$asset->id}.storagePath")
            ->assertJsonPath('data.sections.0.mediaCapability.mode', 'single');
        $this->assertSame(['assetId' => $asset->id, 'focalPoint' => ['x' => 0.25, 'y' => 0.75]], $hero->refresh()->content['media']);
    }

    public function test_media_listing_exposes_event_scoped_canonical_website_section_usage(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $used = $this->assetFor($event);
        $unused = $this->assetFor($event);
        $hero = $website->sections()->where('type', 'hero')->firstOrFail();
        $story = $website->sections()->where('type', 'story')->firstOrFail();
        $hero->update(['content' => [...$hero->content, 'media' => ['assetId' => $used->id]]]);
        $story->update(['content' => ['heading' => 'Story', 'body' => '', 'media' => ['assetId' => $used->id]]]);

        [$otherOwner, $otherEvent] = $this->eventFor(EventMembershipRole::Owner);
        $otherWebsite = $this->initializeWebsite($otherEvent);
        $otherAsset = $this->assetFor($otherEvent);
        $otherHero = $otherWebsite->sections()->where('type', 'hero')->firstOrFail();
        $otherHero->update(['content' => [...$otherHero->content, 'media' => ['assetId' => $used->id]]]);

        $assets = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/media")
            ->assertOk()->assertJsonMissingPath('data.0.storagePath')->json('data'))->keyBy('id');

        $this->assertTrue($assets[$used->id]['usage']['isInUse']);
        $this->assertSame([
            ['mediaId' => $used->id, 'eventId' => $event->id, 'websiteProjectId' => $website->id, 'websiteProjectName' => $website->name,
                'sectionId' => $hero->id, 'sectionType' => 'hero', 'sectionName' => 'Hero', 'reference' => ['type' => 'sectionMedia']],
            ['mediaId' => $used->id, 'eventId' => $event->id, 'websiteProjectId' => $website->id, 'websiteProjectName' => $website->name,
                'sectionId' => $story->id, 'sectionType' => 'story', 'sectionName' => 'Story',
                'reference' => ['type' => 'storyNarrativeBlock', 'elementId' => 'story-legacy-'.$story->id]],
        ], $assets[$used->id]['usage']['references']);
        $this->assertFalse($assets[$unused->id]['usage']['isInUse']);
        $this->assertSame([], $assets[$unused->id]['usage']['references']);
        $this->assertArrayNotHasKey($otherAsset->id, $assets->all());

        $this->actingAs($otherOwner)->getJson("/api/events/{$otherEvent->id}/media")
            ->assertOk()
            ->assertJsonPath('data.0.id', $otherAsset->id)
            ->assertJsonPath('data.0.usage.isInUse', false)
            ->assertJsonCount(0, 'data.0.usage.references');
    }

    public function test_assignment_authorization_and_event_scope_are_enforced(): void
    {
        [$admin, $event] = $this->eventFor(EventMembershipRole::Admin);
        $hero = $this->initializeWebsite($event)->sections()->where('type', 'hero')->firstOrFail();
        $asset = $this->assetFor($event);
        $otherAsset = $this->assetFor(Event::factory()->create());
        $content = fn (string $id): array => ['content' => ['headline' => '', 'subheadline' => '', 'media' => ['assetId' => $id]]];

        $this->actingAs($admin)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", $content($asset->id))->assertOk();
        $this->actingAs(User::factory()->superAdmin()->create())->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", $content($asset->id))->assertOk();
        $this->actingAs(User::factory()->create())->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", $content($asset->id))->assertForbidden();
        $this->actingAs($admin)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", $content($otherAsset->id))->assertUnprocessable();
        $this->actingAs($admin)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", $content((string) Str::ulid()))->assertUnprocessable();
    }

    public function test_focal_point_validation_and_removal_preserve_semantics(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $website->update(['schema_version' => 3]);
        $story = $website->sections()->where('type', 'story')->firstOrFail();
        $asset = $this->assetFor($event);
        $url = "/api/events/{$event->id}/website/sections/{$story->id}";
        $base = fn (?array $framing, bool $withMedia = true): array => ['heading' => '', 'intro' => null, 'elements' => [[
            'id' => 'story-one', 'type' => 'narrativeBlock', 'body' => '',
            ...($withMedia ? ['media' => ['type' => 'image', 'mediaId' => $asset->id]] : []),
        ]], 'mediaFraming' => $framing === null ? [] : ['story-one' => $framing]];

        foreach ([['x' => -0.1, 'y' => 0.5], ['x' => 0.5, 'y' => 1.1], ['x' => 0.5]] as $point) {
            $this->actingAs($owner)->putJson($url, ['content' => $base(['focalPoint' => $point])])->assertUnprocessable();
        }
        foreach ([0.9, 3.1, 'close'] as $zoom) {
            $this->actingAs($owner)->putJson($url, ['content' => $base(['zoom' => $zoom])])->assertUnprocessable();
        }
        $this->actingAs($owner)->putJson($url, ['content' => $base([])])->assertOk();
        $this->assertSame($asset->id, $story->refresh()->content['elements'][0]['media']['mediaId']);
        $this->actingAs($owner)->putJson($url, ['content' => $base(null, false)])->assertOk();
        $this->assertArrayNotHasKey('media', $story->refresh()->content['elements'][0]);
    }

    public function test_section_media_zoom_is_optional_bounded_and_preserved_across_presentations(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $asset = $this->assetFor($event);

        foreach (['hero', 'venue'] as $type) {
            $section = $website->sections()->where('type', $type)->sole();
            $content = $section->content;
            $content['media'] = ['assetId' => $asset->id, 'zoom' => 1.4];
            $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$section->id}", ['content' => $content])->assertOk();
            $this->assertSame(1.4, $section->refresh()->content['media']['zoom']);
        }

        $hero = $website->sections()->where('type', 'hero')->sole();
        $base = [...$hero->content, 'media' => ['assetId' => $asset->id]];
        foreach ([1, 1.5, 3] as $zoom) {
            $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", ['content' => [...$base, 'media' => [...$base['media'], 'zoom' => $zoom]]])->assertOk();
        }
        foreach ([0.9, 3.1, 'close'] as $zoom) {
            $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", ['content' => [...$base, 'media' => [...$base['media'], 'zoom' => $zoom]]])->assertUnprocessable();
        }
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", ['content' => $base])->assertOk();
        $this->assertArrayNotHasKey('zoom', $hero->refresh()->content['media']);

        $zoomed = [...$base, 'media' => [...$base['media'], 'zoom' => 1.8]];
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", ['content' => $zoomed])->assertOk();
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}/appearance", [
            'appearance' => [...WebsiteSectionAppearance::DEFAULT, 'presentation' => 'classic'],
        ])->assertOk();
        $this->assertSame(1.8, $hero->refresh()->content['media']['zoom']);
    }

    public function test_referenced_asset_cannot_be_deleted_until_reference_is_removed(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $venue = $this->initializeWebsite($event)->sections()->where('type', 'venue')->firstOrFail();
        $asset = $this->assetFor($event);
        $content = ['heading' => '', 'name' => '', 'address' => '', 'description' => '', 'media' => ['assetId' => $asset->id]];
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$venue->id}", ['content' => $content])->assertOk();

        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")
            ->assertConflict()
            ->assertJsonPath('code', 'media_asset_in_use')
            ->assertJsonPath('message', 'This image is used by one or more Website Projects.')
            ->assertJsonPath('usage.references.0.reference.type', 'sectionMedia');
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$venue->id}", ['content' => [...$content, 'media' => null]])->assertOk();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertNoContent();
    }

    public function test_people_media_validates_event_scope_focal_points_and_optional_legacy_shape(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $people = $this->initializeWebsite($event)->sections()->where('type', 'people')->sole();
        $asset = $this->assetFor($event);
        $otherAsset = $this->assetFor(Event::factory()->create());
        $url = "/api/events/{$event->id}/website/sections/{$people->id}";
        $person = fn (array $media): array => ['id' => 'person', 'name' => 'Jane Doe', 'role' => null, 'media' => $media];
        $content = fn (array $person): array => ['heading' => 'Wedding Party', 'groups' => [[
            'id' => 'group', 'name' => 'Friends', 'people' => [$person],
        ]]];

        $this->actingAs($owner)->putJson($url, ['content' => $content($person(['assetId' => $asset->id]))])->assertOk();
        $this->assertSame($asset->id, $people->refresh()->content['groups'][0]['people'][0]['media']['assetId']);

        foreach ([
            ['assetId' => $asset->id, 'focalPoint' => ['x' => -0.1, 'y' => 0.5]],
            ['assetId' => $asset->id, 'focalPoint' => ['x' => 0.5]],
            ['assetId' => $asset->id, 'zoom' => 0.9],
            ['assetId' => $asset->id, 'zoom' => 3.1],
            ['assetId' => $asset->id, 'zoom' => 'close'],
        ] as $invalidMedia) {
            $this->actingAs($owner)->putJson($url, ['content' => $content($person($invalidMedia))])->assertUnprocessable();
        }
        $zoomed = $content($person(['assetId' => $asset->id, 'focalPoint' => ['x' => 0.4, 'y' => 0.6], 'zoom' => 2.2]));
        $this->actingAs($owner)->putJson($url, ['content' => $zoomed])->assertOk();
        $this->assertSame(2.2, $people->refresh()->content['groups'][0]['people'][0]['media']['zoom']);
        $this->actingAs($owner)->putJson($url, ['content' => $content($person(['assetId' => $otherAsset->id]))])
            ->assertUnprocessable()->assertJsonValidationErrors('content.groups');
        $this->actingAs($owner)->putJson($url, ['content' => $content($person(['assetId' => (string) Str::ulid()]))])
            ->assertUnprocessable()->assertJsonValidationErrors('content.groups');

        $legacy = ['heading' => 'Wedding Party', 'groups' => [[
            'id' => 'legacy-group', 'name' => 'Family', 'people' => [['id' => 'legacy-person', 'name' => 'Alex', 'role' => null]],
        ]]];
        $this->actingAs($owner)->putJson($url, ['content' => $legacy])->assertOk();
        $this->assertArrayNotHasKey('media', $people->refresh()->content['groups'][0]['people'][0]);
    }

    public function test_people_media_is_batch_resolved_reported_with_context_and_blocks_deletion(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $people = $this->initializeWebsite($event)->sections()->where('type', 'people')->sole();
        $asset = $this->assetFor($event);
        $unused = $this->assetFor($event);
        $content = ['heading' => 'Wedding Party', 'groups' => [[
            'id' => 'friends', 'name' => 'Best Friends', 'people' => [
                ['id' => 'jane', 'name' => 'Jane Doe', 'role' => 'Maid of Honor', 'media' => ['assetId' => $asset->id, 'focalPoint' => ['x' => 0.4, 'y' => 0.3]]],
                ['id' => 'alex', 'name' => 'Alex Cruz', 'role' => null, 'media' => ['assetId' => $asset->id]],
            ],
        ]]];
        $url = "/api/events/{$event->id}/website/sections/{$people->id}";

        $this->actingAs($owner)->putJson($url, ['content' => $content])->assertOk()
            ->assertJsonPath("data.media.{$asset->id}.id", $asset->id)
            ->assertJsonMissingPath("data.media.{$unused->id}")
            ->assertJsonMissingPath("data.media.{$asset->id}.storagePath");
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$people->id}/appearance", [
            'appearance' => [...WebsiteSectionAppearance::DEFAULT, 'presentation' => 'namesOnly'],
        ])->assertOk()->assertJsonPath("data.media.{$asset->id}.id", $asset->id);
        $this->assertSame($content, $people->refresh()->content);
        $this->assertSame($content, $people->refresh()->content);

        $usage = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/media")->assertOk()->json('data'))
            ->firstWhere('id', $asset->id)['usage'];
        $this->assertTrue($usage['isInUse']);
        $this->assertSame([
            ['type' => 'person', 'personId' => 'jane', 'label' => 'Jane Doe', 'groupId' => 'friends', 'groupLabel' => 'Best Friends'],
            ['type' => 'person', 'personId' => 'alex', 'label' => 'Alex Cruz', 'groupId' => 'friends', 'groupLabel' => 'Best Friends'],
        ], collect($usage['references'])->pluck('reference')->all());

        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertConflict();
        $withoutMedia = $content;
        $withoutMedia['groups'][0]['people'] = array_map(fn (array $person): array => [...$person, 'media' => null], $withoutMedia['groups'][0]['people']);
        $this->actingAs($owner)->putJson($url, ['content' => $withoutMedia])->assertOk();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertNoContent();
    }

    public function test_story_block_media_is_resolved_scoped_reported_and_protected(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $story = $this->initializeWebsite($event)->sections()->where('type', 'story')->sole();
        $story->website()->update(['schema_version' => 3]);
        $first = $this->assetFor($event);
        $second = $this->assetFor($event);
        $foreign = $this->assetFor(Event::factory()->create());
        $url = "/api/events/{$event->id}/website/sections/{$story->id}";
        $content = ['heading' => 'Our Story', 'intro' => null, 'elements' => [
            ['id' => 'meeting', 'type' => 'narrativeBlock', 'heading' => 'How we met', 'body' => 'Chapter one', 'media' => ['type' => 'image', 'mediaId' => $first->id]],
            ['id' => 'proposal', 'type' => 'narrativeBlock', 'body' => 'Chapter two', 'media' => ['type' => 'image', 'mediaId' => $second->id]],
        ], 'mediaFraming' => ['meeting' => ['focalPoint' => ['x' => 0.2, 'y' => 0.7], 'zoom' => 1.5]]];

        $this->actingAs($owner)->putJson($url, ['content' => $content])->assertOk()
            ->assertJsonPath("data.media.{$first->id}.id", $first->id)
            ->assertJsonPath("data.media.{$second->id}.id", $second->id)
            ->assertJsonMissingPath("data.media.{$first->id}.storagePath");

        $usage = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/media")->assertOk()->json('data'))
            ->firstWhere('id', $first->id)['usage'];
        $this->assertSame(['type' => 'storyNarrativeBlock', 'elementId' => 'meeting', 'label' => 'How we met'], $usage['references'][0]['reference']);
        $this->assertArrayNotHasKey('blockId', $usage['references'][0]['reference']);
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$first->id}")->assertConflict();

        $withoutFirst = $content;
        unset($withoutFirst['elements'][0]['media'], $withoutFirst['mediaFraming']['meeting']);
        $this->actingAs($owner)->putJson($url, ['content' => $withoutFirst])->assertOk();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$first->id}")->assertNoContent();

        $invalid = $content;
        $invalid['elements'][0]['media'] = ['type' => 'image', 'mediaId' => $foreign->id];
        $this->actingAs($owner)->putJson($url, ['content' => $invalid])->assertUnprocessable()->assertJsonValidationErrors('content.elements');
    }

    public function test_usage_is_project_aware_deduplicated_and_blocks_until_every_project_reference_is_removed(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $firstProject = $this->initializeWebsite($event);
        $secondProject = app(CreateWebsiteProject::class)->handle($event, 'Modern Project', WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $asset = $this->assetFor($event);
        $firstHero = $firstProject->sections()->where('type', 'hero')->sole();
        $secondHero = $secondProject->sections()->where('type', 'hero')->sole();
        $firstHero->update(['content' => [...$firstHero->content, 'media' => ['assetId' => $asset->id]]]);
        $secondHero->update(['content' => [...$secondHero->content, 'media' => ['assetId' => $asset->id]]]);

        $usage = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/media")->assertOk()->json('data'))
            ->firstWhere('id', $asset->id)['usage'];
        $this->assertCount(2, $usage['references']);
        $this->assertEqualsCanonicalizing([$firstProject->id, $secondProject->id], collect($usage['references'])->pluck('websiteProjectId')->all());
        $this->assertEqualsCanonicalizing([$firstProject->name, 'Modern Project'], collect($usage['references'])->pluck('websiteProjectName')->all());

        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")
            ->assertConflict()->assertJsonCount(2, 'usage.references');
        $firstHero->update(['content' => [...$firstHero->content, 'media' => null]]);
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")
            ->assertConflict()->assertJsonCount(1, 'usage.references')
            ->assertJsonPath('usage.references.0.websiteProjectId', $secondProject->id);
        $secondHero->update(['content' => [...$secondHero->content, 'media' => null]]);
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertNoContent();
    }

    public function test_exact_story_identity_is_deduplicated_but_distinct_elements_remain(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $story = $this->initializeWebsite($event)->sections()->where('type', 'story')->sole();
        $asset = $this->assetFor($event);
        $story->update(['content' => ['heading' => 'Historical', 'blocks' => [
            ['id' => 'same', 'body' => 'One', 'media' => ['assetId' => $asset->id]],
            ['id' => 'same', 'body' => 'Duplicate', 'media' => ['assetId' => $asset->id]],
            ['id' => 'different', 'body' => 'Distinct', 'media' => ['assetId' => $asset->id]],
        ]]]);

        $references = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/media")->assertOk()->json('data'))
            ->firstWhere('id', $asset->id)['usage']['references'];
        $this->assertSame(['same', 'different'], collect($references)->pluck('reference.elementId')->all());
    }

    private function eventFor(EventMembershipRole $role): array
    {
        $user = User::factory()->create();
        $event = Event::factory()->create();
        $event->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return [$user, $event];
    }

    private function assetFor(Event $event): MediaAsset
    {
        $asset = MediaAsset::query()->create([
            'id' => (string) Str::ulid(), 'event_id' => $event->id, 'original_filename' => 'image.jpg', 'mime_type' => 'image/jpeg',
            'extension' => 'jpg', 'width' => 1200, 'height' => 800, 'size_bytes' => 100, 'content_hash' => hash('sha256', (string) Str::ulid()),
            'storage_disk' => 'media-test', 'original_path' => 'events/'.$event->id.'/'.Str::ulid().'/original.jpg',
        ]);
        $asset->variants()->create(['id' => (string) Str::ulid(), 'variant_key' => 'web', 'mime_type' => 'image/webp', 'width' => 1200, 'height' => 800, 'size_bytes' => 80, 'storage_disk' => 'media-test', 'storage_path' => 'web.webp']);
        Storage::disk('media-test')->put($asset->original_path, 'original');
        Storage::disk('media-test')->put('web.webp', 'web');

        return $asset;
    }
}
