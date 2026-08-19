<?php

namespace Tests\Feature;

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
        $story->update(['content' => [...$story->content, 'media' => ['assetId' => $used->id]]]);

        [$otherOwner, $otherEvent] = $this->eventFor(EventMembershipRole::Owner);
        $otherWebsite = $this->initializeWebsite($otherEvent);
        $otherAsset = $this->assetFor($otherEvent);
        $otherHero = $otherWebsite->sections()->where('type', 'hero')->firstOrFail();
        $otherHero->update(['content' => [...$otherHero->content, 'media' => ['assetId' => $used->id]]]);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/template", [
            'templateKey' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1,
        ])->assertOk();

        $assets = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/media")
            ->assertOk()->assertJsonMissingPath('data.0.storagePath')->json('data'))->keyBy('id');

        $this->assertTrue($assets[$used->id]['usage']['isInUse']);
        $this->assertSame([
            ['sectionId' => $hero->id, 'type' => 'hero', 'displayName' => 'Hero'],
            ['sectionId' => $story->id, 'type' => 'story', 'displayName' => 'Story'],
        ], $assets[$used->id]['usage']['website']['sections']);
        $this->assertFalse($assets[$unused->id]['usage']['isInUse']);
        $this->assertSame([], $assets[$unused->id]['usage']['website']['sections']);
        $this->assertArrayNotHasKey($otherAsset->id, $assets->all());

        $this->actingAs($otherOwner)->getJson("/api/events/{$otherEvent->id}/media")
            ->assertOk()
            ->assertJsonPath('data.0.id', $otherAsset->id)
            ->assertJsonPath('data.0.usage.isInUse', false)
            ->assertJsonCount(0, 'data.0.usage.website.sections');
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

    public function test_focal_point_validation_removal_and_template_switch_preserve_semantics(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $story = $website->sections()->where('type', 'story')->firstOrFail();
        $asset = $this->assetFor($event);
        $url = "/api/events/{$event->id}/website/sections/{$story->id}";
        $base = ['heading' => '', 'body' => ''];

        foreach ([['x' => -0.1, 'y' => 0.5], ['x' => 0.5, 'y' => 1.1], ['x' => 0.5]] as $point) {
            $this->actingAs($owner)->putJson($url, ['content' => $base + ['media' => ['assetId' => $asset->id, 'focalPoint' => $point]]])->assertUnprocessable();
        }
        $this->actingAs($owner)->putJson($url, ['content' => $base + ['media' => ['assetId' => $asset->id]]])->assertOk();
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/template", ['templateKey' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1])->assertOk();
        $this->assertSame($asset->id, $story->refresh()->content['media']['assetId']);
        $this->actingAs($owner)->putJson($url, ['content' => $base + ['media' => null]])->assertOk();
        $this->assertNull($story->refresh()->content['media']);
    }

    public function test_section_media_zoom_is_optional_bounded_and_preserved_across_presentations_and_templates(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $asset = $this->assetFor($event);

        foreach (['hero', 'story', 'venue'] as $type) {
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
            'appearance' => [...WebsiteSectionAppearance::DEFAULT, 'presentation' => 'framed'],
        ])->assertOk();
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/template", ['templateKey' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1])->assertOk();
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
            ->assertUnprocessable()->assertJsonPath('errors.asset.0', 'This image is currently used by your Website and cannot be deleted.');
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
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/template", [
            'templateKey' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1,
        ])->assertOk();
        $this->assertSame($content, $people->refresh()->content);

        $usage = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/media")->assertOk()->json('data'))
            ->firstWhere('id', $asset->id)['usage'];
        $this->assertTrue($usage['isInUse']);
        $this->assertSame([
            ['groupId' => 'friends', 'groupName' => 'Best Friends', 'personId' => 'jane', 'personName' => 'Jane Doe'],
            ['groupId' => 'friends', 'groupName' => 'Best Friends', 'personId' => 'alex', 'personName' => 'Alex Cruz'],
        ], collect($usage['website']['sections'])->pluck('context')->all());

        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertUnprocessable();
        $withoutMedia = $content;
        $withoutMedia['groups'][0]['people'] = array_map(fn (array $person): array => [...$person, 'media' => null], $withoutMedia['groups'][0]['people']);
        $this->actingAs($owner)->putJson($url, ['content' => $withoutMedia])->assertOk();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertNoContent();
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
