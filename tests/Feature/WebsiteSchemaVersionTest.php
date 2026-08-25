<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\CreateWebsiteProject;
use App\Exceptions\UnsupportedWebsiteSchemaVersion;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Website;
use App\Website\StoryContentNormalizer;
use App\Website\WebsiteDraftNormalizer;
use App\Website\WebsiteSchema;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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
        foreach ([$classic->json('data.id'), $modern->json('data.id')] as $websiteId) {
            $story = Website::findOrFail($websiteId)->sections()->where('type', 'story')->sole();
            $this->assertSame(['heading' => '', 'intro' => null, 'elements' => [], 'mediaFraming' => []], $story->content);
            $this->assertStringContainsString('"mediaFraming":{}', DB::table('website_sections')->where('id', $story->id)->value('content'));
        }
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

    public function test_v3_design_migration_adds_empty_sparse_defaults_and_preserves_legacy_values(): void
    {
        [$event] = $this->event();
        $classic = app(CreateWebsiteProject::class)->handle($event, 'Classic', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $modern = app(CreateWebsiteProject::class)->handle($event, 'Modern', WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $stored = [
            $classic->id => ['colorTheme' => 'terracotta', 'fontSet' => 'editorial', 'artStyle' => 'minimal', 'futureMetadata' => 'keep'],
            $modern->id => ['colorTheme' => 'plum', 'fontSet' => 'fashion', 'artStyle' => 'offset', 'futureMetadata' => 'keep'],
        ];
        foreach ($stored as $id => $settings) {
            DB::table('websites')->where('id', $id)->update([
                'design_settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                'schema_version' => 2,
            ]);
        }
        $migration = require database_path('migrations/2026_08_24_000000_upgrade_website_design_settings_to_v3.php');

        $migration->up();
        foreach ($stored as $id => $settings) {
            $row = DB::table('websites')->where('id', $id)->first(['design_settings', 'schema_version']);
            $this->assertSame(3, $row->schema_version);
            $this->assertSame([...$settings, 'projectDefaults' => []], json_decode($row->design_settings, true, flags: JSON_THROW_ON_ERROR));
            $this->assertStringContainsString('"projectDefaults":{}', $row->design_settings);
        }

        $migration->down();
        foreach ($stored as $id => $settings) {
            $row = DB::table('websites')->where('id', $id)->first(['design_settings', 'schema_version']);
            $this->assertSame(2, $row->schema_version);
            $this->assertSame($settings, json_decode($row->design_settings, true, flags: JSON_THROW_ON_ERROR));
        }
    }

    public function test_v3_design_migration_rollback_refuses_to_discard_sparse_overrides_before_mutation(): void
    {
        [$event] = $this->event();
        $first = app(CreateWebsiteProject::class)->handle($event, 'First', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $second = app(CreateWebsiteProject::class)->handle($event, 'Second', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $settings = $second->design_settings;
        $settings['projectDefaults'] = ['headingFontId' => 'romantic-serif'];
        DB::table('websites')->where('id', $second->id)->update(['design_settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
        $before = DB::table('websites')->whereIn('id', [$first->id, $second->id])->orderBy('id')->get(['id', 'design_settings', 'schema_version'])->all();
        $migration = require database_path('migrations/2026_08_24_000000_upgrade_website_design_settings_to_v3.php');

        try {
            $migration->down();
            $this->fail('Rollback must refuse persisted Project Design Default overrides.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('persisted Project Design Default overrides', $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('websites')->whereIn('id', [$first->id, $second->id])->orderBy('id')->get(['id', 'design_settings', 'schema_version'])->all());
    }

    public function test_v0_and_v1_normalize_to_runtime_v2_without_writes_and_preserve_story_adapter(): void
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

        $this->assertSame(2, $first['schemaVersion']);
        $this->assertSame($firstStory['content'], $secondStory['content']);
        $this->assertSame('story-legacy-'.$story->id, $firstStory['content']['elements'][0]['id']);
        $this->assertSame('Original narrative', $firstStory['content']['elements'][0]['body']);
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
            ->assertJsonPath('data.sections.2.content.elements.0.media.mediaId', $asset->id)
            ->assertJsonPath("data.media.{$asset->id}.id", $asset->id);
        $this->assertSame($legacy, $story->fresh()->content);
    }

    public function test_future_version_returns_stable_conflict_for_project_and_legacy_reads(): void
    {
        [$event, $owner] = $this->event();
        $website = app(CreateWebsiteProject::class)->handle($event, 'Website', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        DB::table('websites')->where('id', $website->id)->update(['schema_version' => WebsiteSchema::CURRENT_SCHEMA_VERSION + 1]);
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

    public function test_partial_mutations_preserve_v0_storage_and_design_save_promotes_to_v3(): void
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
            $response->assertOk()->assertJsonPath('data.schemaVersion', 2);
            $this->assertSame(WebsiteSchema::LEGACY_SCHEMA_VERSION, $website->fresh()->schema_version);
            $this->assertSame($legacyStory, $story->fresh()->content);
        };

        $assertVersion($this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}", ['content' => ['headline' => 'Changed', 'subheadline' => '']]));
        $assertVersion($this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}/appearance", ['appearance' => WebsiteSectionAppearance::DEFAULT]));
        $assertVersion($this->actingAs($owner)->putJson("{$base}/sections/{$hero->id}/enabled", ['isEnabled' => false]));
        $ids = $website->sections()->pluck('id')->reverse()->values()->all();
        $assertVersion($this->actingAs($owner)->putJson("{$base}/sections/order", ['sectionIds' => $ids]));

        $website->update(['name' => 'Renamed']);
        $this->assertSame(WebsiteSchema::LEGACY_SCHEMA_VERSION, $website->fresh()->schema_version);

        $this->actingAs($owner)
            ->putJson("{$base}/design", ['designSettings' => $website->design_settings])
            ->assertOk()
            ->assertJsonPath('data.schemaVersion', 3);
        $this->assertSame(3, $website->fresh()->schema_version);
        $this->assertSame($legacyStory, $story->fresh()->content);
    }

    #[DataProvider('promotableSourceVersions')]
    public function test_successful_canonical_story_save_promotes_old_source_versions(int $sourceVersion): void
    {
        [$event, $owner] = $this->event();
        $website = app(CreateWebsiteProject::class)->handle($event, 'Historical', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        DB::table('websites')->where('id', $website->id)->update(['schema_version' => $sourceVersion]);
        $story = $website->sections()->where('type', 'story')->sole();
        $story->update(['content' => ['heading' => 'Old', 'intro' => null, 'blocks' => [['id' => 'old', 'heading' => null, 'body' => 'Old body']]]]);
        $canonical = app(StoryContentNormalizer::class)->normalizeToV4($story->id, ['heading' => 'New', 'intro' => null, 'elements' => [['id' => 'new', 'type' => 'narrativeBlock', 'body' => 'New body']], 'mediaFraming' => []]);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/websites/{$website->id}/sections/{$story->id}", ['schemaVersion' => 4, 'content' => $canonical])->assertOk();
        $this->assertSame(WebsiteSchema::CURRENT_SCHEMA_VERSION, $website->fresh()->schema_version);
        $this->assertSame('new', $story->fresh()->content['elements'][0]['id']);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/websites/{$website->id}/sections/{$story->id}", ['schemaVersion' => 4, 'content' => $canonical])->assertOk();
        $this->assertSame(WebsiteSchema::CURRENT_SCHEMA_VERSION, $website->fresh()->schema_version);
    }

    public static function promotableSourceVersions(): array
    {
        return [[0], [1], [2]];
    }

    public function test_failed_story_save_leaves_source_content_and_version_unchanged(): void
    {
        [$event, $owner] = $this->event();
        $website = app(CreateWebsiteProject::class)->handle($event, 'Historical', WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        DB::table('websites')->where('id', $website->id)->update(['schema_version' => 1]);
        $story = $website->sections()->where('type', 'story')->sole();
        $stored = ['heading' => 'Old', 'intro' => null, 'blocks' => [['id' => 'old', 'heading' => null, 'body' => 'Old body']]];
        $story->update(['content' => $stored]);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/websites/{$website->id}/sections/{$story->id}", [
            'content' => ['heading' => 'Bad', 'intro' => null, 'elements' => [['id' => 'bad', 'type' => 'text', 'text' => 'No']], 'mediaFraming' => []],
        ])->assertUnprocessable();

        $this->assertSame(1, $website->fresh()->schema_version);
        $this->assertSame($stored, $story->fresh()->content);
    }

    /** @return array{Event, User} */
    private function event(): array
    {
        $owner = User::factory()->create();

        return [app(CreateEvent::class)->handle($owner, ['name' => fake()->words(3, true)]), $owner];
    }
}
