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
            'content' => [...$hero->content, 'backgroundMedia' => ['assetId' => $asset->id, 'focalPoint' => ['x' => 0.25, 'y' => 0.75]]],
        ]);

        $response->assertOk()->assertJsonPath("data.media.{$asset->id}.id", $asset->id)
            ->assertJsonPath("data.media.{$asset->id}.web.url", route('events.media.variants.show', ['event' => $event->id, 'asset' => $asset->id, 'variant' => 'web']))
            ->assertJsonMissingPath("data.media.{$unused->id}")
            ->assertJsonMissingPath("data.media.{$asset->id}.storagePath")
            ->assertJsonPath('data.sections.0.mediaCapability.mode', 'single');
        $this->assertSame(['assetId' => $asset->id, 'focalPoint' => ['x' => 0.25, 'y' => 0.75]], $hero->refresh()->content['backgroundMedia']);
    }

    public function test_assignment_authorization_and_event_scope_are_enforced(): void
    {
        [$admin, $event] = $this->eventFor(EventMembershipRole::Admin);
        $hero = $this->initializeWebsite($event)->sections()->where('type', 'hero')->firstOrFail();
        $asset = $this->assetFor($event);
        $otherAsset = $this->assetFor(Event::factory()->create());
        $content = fn (string $id): array => ['content' => [...$hero->content, 'backgroundMedia' => ['assetId' => $id]]];

        $this->actingAs($admin)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", $content($asset->id))->assertOk();
        $this->actingAs(User::factory()->superAdmin()->create())->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", $content($asset->id))->assertOk();
        $this->actingAs(User::factory()->create())->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", $content($asset->id))->assertForbidden();
        $this->actingAs($admin)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", $content($otherAsset->id))->assertUnprocessable();
        $this->actingAs($admin)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", $content((string) Str::ulid()))->assertUnprocessable();
    }

    public function test_responsive_hero_assets_and_framing_round_trip_and_protect_device_only_media(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $hero = $this->initializeWebsite($event)->sections()->where('type', 'hero')->sole();
        $desktop = $this->assetFor($event);
        $tablet = $this->assetFor($event);
        $mobile = $this->assetFor($event);
        $backgroundMedia = [
            'assetId' => $desktop->id, 'focalPoint' => ['x' => .2, 'y' => .7], 'zoom' => 1.8,
            'responsive' => [
                'tablet' => ['assetId' => $tablet->id, 'zoom' => .7],
                'mobile' => ['assetId' => $mobile->id, 'focalPoint' => ['x' => .8, 'y' => .3], 'zoom' => .42],
            ],
        ];
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}";
        $response = $this->actingAs($owner)->putJson($url, ['content' => [...$hero->content, 'backgroundMedia' => $backgroundMedia]])->assertOk();

        $saved = $hero->refresh()->content['backgroundMedia'];
        $this->assertSame(.7, $saved['responsive']['tablet']['zoom']);
        $this->assertSame(.42, $saved['responsive']['mobile']['zoom']);
        foreach ([$desktop, $tablet, $mobile] as $asset) {
            $response->assertJsonPath("data.media.{$asset->id}.id", $asset->id);
        }
        $response->assertJsonCount(3, 'data.media');
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$mobile->id}")->assertConflict();
        unset($saved['responsive']['mobile']);
        $this->actingAs($owner)->putJson($url, ['content' => [...$hero->content, 'backgroundMedia' => $saved]])->assertOk();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$mobile->id}")->assertNoContent();
    }

    public function test_section_media_zoom_is_optional_bounded_and_preserved_across_presentations(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $asset = $this->assetFor($event);

        $hero = $website->sections()->where('type', 'hero')->sole();
        $base = [...$hero->content, 'backgroundMedia' => ['assetId' => $asset->id]];
        foreach ([.01, .42, 1, 1.5, 3] as $zoom) {
            $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", ['content' => [...$base, 'backgroundMedia' => [...$base['backgroundMedia'], 'zoom' => $zoom]]])->assertOk();
        }
        foreach ([0, -.1, 3.1, 'close'] as $zoom) {
            $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", ['content' => [...$base, 'backgroundMedia' => [...$base['backgroundMedia'], 'zoom' => $zoom]]])->assertUnprocessable();
        }
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", ['content' => $base])->assertOk();
        $this->assertArrayNotHasKey('zoom', $hero->refresh()->content['backgroundMedia']);

        $zoomed = [...$base, 'backgroundMedia' => [...$base['backgroundMedia'], 'zoom' => 1.8]];
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", ['content' => $zoomed])->assertOk();
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}/appearance", [
            'appearance' => [...WebsiteSectionAppearance::DEFAULT, 'height' => 'screen'],
        ])->assertOk();
        $this->assertSame(1.8, $hero->refresh()->content['backgroundMedia']['zoom']);
    }

    public function test_referenced_asset_cannot_be_deleted_until_reference_is_removed(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $hero = $this->initializeWebsite($event)->sections()->where('type', 'hero')->firstOrFail();
        $asset = $this->assetFor($event);
        $content = [...$hero->content, 'backgroundMedia' => ['assetId' => $asset->id]];
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", ['content' => $content])->assertOk();

        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")
            ->assertConflict()
            ->assertJsonPath('code', 'media_asset_in_use')
            ->assertJsonPath('message', 'This image is used by one or more Website Projects.')
            ->assertJsonPath('usage.references.0.reference.type', 'sectionMedia');
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}", ['content' => [...$content, 'backgroundMedia' => null]])->assertOk();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertNoContent();
    }

    public function test_group_background_media_round_trips_blocks_deletion_and_rejects_unavailable_assets(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $website = $this->initializeWebsite($event);
        $blank = $website->sections()->where('type', 'hero')->firstOrFail();
        $asset = $this->assetFor($event);
        $group = ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [],
            'backgroundMedia' => ['assetId' => $asset->id, 'focalPoint' => ['x' => .25, 'y' => .75], 'zoom' => 1.8],
            'appearance' => ['backgroundImageOpacity' => 40], 'layout' => ['direction' => 'horizontal', 'division' => '60-40'],
        ];
        $content = ['backgroundMedia' => null, 'childFlow' => ['elements' => [$group], 'order' => [['kind' => 'element', 'id' => 'group']]]];
        $url = "/api/events/{$event->id}/website/sections/{$blank->id}";
        $this->actingAs($owner)->putJson($url, compact('content'))->assertOk();
        $savedGroup = $blank->refresh()->content['childFlow']['elements'][0];
        $this->assertSame($group, $savedGroup);
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertConflict();

        $invalid = $content;
        $invalid['childFlow']['elements'][0]['backgroundMedia']['assetId'] = (string) Str::ulid();
        $this->actingAs($owner)->putJson($url, ['content' => $invalid])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['content' => ['backgroundMedia' => null, 'childFlow' => ['elements' => [[...$group, 'backgroundMedia' => null]], 'order' => [['kind' => 'element', 'id' => 'group']]]]])->assertOk();
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")->assertNoContent();
    }

    public function test_usage_is_project_aware_deduplicated_and_blocks_until_every_project_reference_is_removed(): void
    {
        [$owner, $event] = $this->eventFor(EventMembershipRole::Owner);
        $firstProject = $this->initializeWebsite($event);
        $secondProject = app(CreateWebsiteProject::class)->handle($event, 'Modern Project', WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $asset = $this->assetFor($event);
        $firstHero = $firstProject->sections()->where('type', 'hero')->sole();
        $secondHero = $secondProject->sections()->where('type', 'hero')->sole();
        $firstHero->update(['content' => [...$firstHero->content, 'backgroundMedia' => ['assetId' => $asset->id]]]);
        $secondHero->update(['content' => [...$secondHero->content, 'backgroundMedia' => ['assetId' => $asset->id]]]);

        $usage = collect($this->actingAs($owner)->getJson("/api/events/{$event->id}/media")->assertOk()->json('data'))
            ->firstWhere('id', $asset->id)['usage'];
        $this->assertCount(2, $usage['references']);
        $this->assertEqualsCanonicalizing([$firstProject->id, $secondProject->id], collect($usage['references'])->pluck('websiteProjectId')->all());
        $this->assertEqualsCanonicalizing([$firstProject->name, 'Modern Project'], collect($usage['references'])->pluck('websiteProjectName')->all());

        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")
            ->assertConflict()->assertJsonCount(2, 'usage.references');
        $firstHero->update(['content' => [...$firstHero->content, 'backgroundMedia' => null]]);
        $this->actingAs($owner)->deleteJson("/api/events/{$event->id}/media/{$asset->id}")
            ->assertConflict()->assertJsonCount(1, 'usage.references')
            ->assertJsonPath('usage.references.0.websiteProjectId', $secondProject->id);
        $secondHero->update(['content' => [...$secondHero->content, 'backgroundMedia' => null]]);
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
