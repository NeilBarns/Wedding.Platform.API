<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\CreateWebsiteProject;
use App\Exceptions\UnsupportedWebsiteSchemaVersion;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Website;
use App\Website\WebsiteDraftNormalizer;
use App\Website\WebsiteSchema;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteSchemaVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_new_plural_and_legacy_projects_store_and_return_current_version(): void
    {
        [$event, $owner] = $this->event();
        $classic = $this->actingAs($owner)->postJson("/api/events/{$event->id}/websites", [
            'name' => 'Classic',
            'templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
        ])->assertCreated()->assertJsonPath('data.schemaVersion', WebsiteSchema::CURRENT_SCHEMA_VERSION);
        $modern = $this->actingAs($owner)->postJson("/api/events/{$event->id}/websites", [
            'name' => 'Modern',
            'templateKey' => WebsiteTemplateRegistry::MODERN_EDITORIAL_V1,
        ])->assertCreated()->assertJsonPath('data.schemaVersion', WebsiteSchema::CURRENT_SCHEMA_VERSION);

        $this->assertSame(WebsiteSchema::CURRENT_SCHEMA_VERSION, Website::findOrFail($classic->json('data.id'))->schema_version);
        $this->assertSame(WebsiteSchema::CURRENT_SCHEMA_VERSION, Website::findOrFail($modern->json('data.id'))->schema_version);
        $classicWebsite = Website::findOrFail($classic->json('data.id'));
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/websites/{$classicWebsite->id}/design", [
            'designSettings' => $classicWebsite->design_settings,
        ])->assertOk()->assertJsonPath('data.schemaVersion', WebsiteSchema::CURRENT_SCHEMA_VERSION);
        $this->assertSame(WebsiteSchema::CURRENT_SCHEMA_VERSION, $classicWebsite->fresh()->schema_version);

        [$legacyEvent, $legacyOwner] = $this->event();
        $legacy = $this->actingAs($legacyOwner)->postJson("/api/events/{$legacyEvent->id}/website", [
            'templateKey' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
        ])->assertCreated()->assertJsonPath('data.schemaVersion', WebsiteSchema::CURRENT_SCHEMA_VERSION);
        $this->assertSame(WebsiteSchema::CURRENT_SCHEMA_VERSION, Website::findOrFail($legacy->json('data.id'))->schema_version);
    }

    public function test_migration_backfills_legacy_version_and_rollback_preserves_sections(): void
    {
        [$event] = $this->event();
        $website = app(CreateWebsiteProject::class)->handle($event, 'Existing', WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $websiteBefore = DB::table('websites')->where('id', $website->id)
            ->first(['id', 'event_id', 'name', 'template_key', 'design_settings', 'created_at', 'updated_at']);
        $sectionsBefore = DB::table('website_sections')->where('website_id', $website->id)
            ->orderBy('id')->get(['id', 'website_id', 'type', 'sort_order', 'is_enabled', 'content', 'appearance', 'created_at', 'updated_at'])->all();
        $migration = require database_path('migrations/2026_08_23_000000_add_schema_version_to_websites.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('websites', 'schema_version'));
        $this->assertEquals($sectionsBefore, DB::table('website_sections')->where('website_id', $website->id)
            ->orderBy('id')->get(['id', 'website_id', 'type', 'sort_order', 'is_enabled', 'content', 'appearance', 'created_at', 'updated_at'])->all());

        $migration->up();

        $this->assertTrue(Schema::hasColumn('websites', 'schema_version'));
        $this->assertSame(WebsiteSchema::LEGACY_SCHEMA_VERSION, Website::findOrFail($website->id)->schema_version);
        $this->assertEquals($websiteBefore, DB::table('websites')->where('id', $website->id)
            ->first(['id', 'event_id', 'name', 'template_key', 'design_settings', 'created_at', 'updated_at']));
        $this->assertEquals($sectionsBefore, DB::table('website_sections')->where('website_id', $website->id)
            ->orderBy('id')->get(['id', 'website_id', 'type', 'sort_order', 'is_enabled', 'content', 'appearance', 'created_at', 'updated_at'])->all());
    }

    public function test_v0_and_v1_normalize_to_runtime_v1_without_writes_and_preserve_story_adapter(): void
    {
        [$event] = $this->event();
        $website = app(CreateWebsiteProject::class)->handle($event, 'Historical', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        DB::table('websites')->where('id', $website->id)->update(['schema_version' => WebsiteSchema::LEGACY_SCHEMA_VERSION]);
        $story = $website->sections()->where('type', 'story')->sole();
        $legacy = ['heading' => 'Our Story', 'body' => 'Original narrative', 'media' => null];
        $story->update(['content' => $legacy]);
        $beforeWebsiteTimestamp = $website->fresh()->updated_at->toJSON();
        $beforeSectionTimestamp = $story->fresh()->updated_at->toJSON();

        $first = app(WebsiteDraftNormalizer::class)->normalize($website->fresh()->load('sections.website'));
        $second = app(WebsiteDraftNormalizer::class)->normalize($website->fresh()->load('sections.website'));
        $firstStory = collect($first['sections'])->first(fn (array $item): bool => $item['section']->type === 'story');
        $secondStory = collect($second['sections'])->first(fn (array $item): bool => $item['section']->type === 'story');

        $this->assertSame(WebsiteSchema::CURRENT_SCHEMA_VERSION, $first['schemaVersion']);
        $this->assertSame($firstStory['content'], $secondStory['content']);
        $this->assertSame('story-legacy-'.$story->id, $firstStory['content']['blocks'][0]['id']);
        $this->assertSame('Original narrative', $firstStory['content']['blocks'][0]['body']);
        $this->assertSame($legacy, $story->fresh()->content);
        $this->assertSame(WebsiteSchema::LEGACY_SCHEMA_VERSION, $website->fresh()->schema_version);
        $this->assertSame($beforeWebsiteTimestamp, $website->fresh()->updated_at->toJSON());
        $this->assertSame($beforeSectionTimestamp, $story->fresh()->updated_at->toJSON());

        DB::table('websites')->where('id', $website->id)->update(['schema_version' => WebsiteSchema::CURRENT_SCHEMA_VERSION]);
        $this->assertSame(WebsiteSchema::CURRENT_SCHEMA_VERSION, app(WebsiteDraftNormalizer::class)->normalize($website->fresh())['schemaVersion']);
    }

    public function test_non_story_adapter_is_identity_preserving(): void
    {
        [$event] = $this->event();
        $website = app(CreateWebsiteProject::class)->handle($event, 'Website', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $hero = $website->sections()->where('type', 'hero')->sole();
        $content = ['headline' => 'Exact', 'subheadline' => 'Value', 'media' => null];
        $hero->update(['content' => $content]);
        $draft = app(WebsiteDraftNormalizer::class)->normalize($website->fresh()->load('sections.website'));
        $normalized = collect($draft['sections'])->first(fn (array $item): bool => $item['section']->id === $hero->id);

        $this->assertSame($content, $normalized['content']);
    }

    public function test_media_resolution_scans_normalized_legacy_story_content(): void
    {
        [$event, $owner] = $this->event();
        $website = app(CreateWebsiteProject::class)->handle($event, 'Historical', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        DB::table('websites')->where('id', $website->id)->update(['schema_version' => WebsiteSchema::LEGACY_SCHEMA_VERSION]);
        $asset = MediaAsset::query()->create([
            'id' => (string) Str::ulid(), 'event_id' => $event->id, 'original_filename' => 'story.jpg', 'mime_type' => 'image/jpeg',
            'extension' => 'jpg', 'width' => 1200, 'height' => 800, 'size_bytes' => 100, 'content_hash' => hash('sha256', (string) Str::ulid()),
            'storage_disk' => 'media-test', 'original_path' => 'story-original.jpg',
        ]);
        $asset->variants()->create([
            'id' => (string) Str::ulid(), 'variant_key' => 'web', 'mime_type' => 'image/webp', 'width' => 1200,
            'height' => 800, 'size_bytes' => 80, 'storage_disk' => 'media-test', 'storage_path' => 'story-web.webp',
        ]);
        $story = $website->sections()->where('type', 'story')->sole();
        $legacy = ['heading' => 'Story', 'body' => 'Legacy', 'media' => ['assetId' => $asset->id]];
        $story->update(['content' => $legacy]);

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$website->id}")
            ->assertOk()
            ->assertJsonPath('data.sections.2.content.blocks.0.media.assetId', $asset->id)
            ->assertJsonPath("data.media.{$asset->id}.id", $asset->id);
        $this->assertSame($legacy, $story->fresh()->content);
    }

    public function test_future_version_returns_stable_conflict_for_project_and_legacy_reads(): void
    {
        [$event, $owner] = $this->event();
        $website = app(CreateWebsiteProject::class)->handle($event, 'Website', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        DB::table('websites')->where('id', $website->id)->update(['schema_version' => 2]);
        $error = [
            'code' => 'website_schema_version_unsupported',
            'message' => 'This Website Project uses an unsupported schema version.',
        ];

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/websites/{$website->id}")
            ->assertConflict()->assertJson($error);
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")
            ->assertConflict()->assertJson($error);
    }

    public function test_negative_in_memory_version_is_rejected(): void
    {
        $website = Website::factory()->make(['schema_version' => -1]);

        $this->expectException(UnsupportedWebsiteSchemaVersion::class);
        app(WebsiteDraftNormalizer::class)->normalize($website);
    }

    public function test_partial_mutations_preserve_v0_storage_and_return_runtime_v1(): void
    {
        [$event, $owner] = $this->event();
        $website = app(CreateWebsiteProject::class)->handle($event, 'Historical', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        DB::table('websites')->where('id', $website->id)->update(['schema_version' => WebsiteSchema::LEGACY_SCHEMA_VERSION]);
        $story = $website->sections()->where('type', 'story')->sole();
        $legacyStory = ['heading' => 'Legacy', 'body' => 'Do not rewrite'];
        $story->update(['content' => $legacyStory]);
        $hero = $website->sections()->where('type', 'hero')->sole();
        $base = "/api/events/{$event->id}/websites/{$website->id}";
        $assertVersion = function ($response) use ($website, $story, $legacyStory): void {
            $response->assertOk()->assertJsonPath('data.schemaVersion', WebsiteSchema::CURRENT_SCHEMA_VERSION);
            $this->assertSame(WebsiteSchema::LEGACY_SCHEMA_VERSION, $website->fresh()->schema_version);
            $this->assertSame($legacyStory, $story->fresh()->content);
        };

        $assertVersion($this->actingAs($owner)->putJson("{$base}/design", ['designSettings' => $website->design_settings]));
        $assertVersion($this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}", ['content' => ['headline' => 'Changed', 'subheadline' => '']]));
        $assertVersion($this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}/appearance", ['appearance' => WebsiteSectionAppearance::DEFAULT]));
        $assertVersion($this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}/enabled", ['isEnabled' => false]));
        $ids = $website->sections()->pluck('id')->reverse()->values()->all();
        $assertVersion($this->actingAs($owner)->putJson("{$base}/sections/order", ['sectionIds' => $ids]));

        $website->update(['name' => 'Renamed']);
        $this->assertSame(WebsiteSchema::LEGACY_SCHEMA_VERSION, $website->fresh()->schema_version);
    }

    /** @return array{Event, User} */
    private function event(): array
    {
        $owner = User::factory()->create();

        return [app(CreateEvent::class)->handle($owner, ['name' => fake()->words(3, true)]), $owner];
    }
}
