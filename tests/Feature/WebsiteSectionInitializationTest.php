<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\InitializeWebsiteSections;
use App\Enums\EventMembershipRole;
use App\Enums\PlatformRole;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteSectionInitializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_wedding_website_receives_all_default_sections(): void
    {
        $website = Website::factory()->create();

        app(InitializeWebsiteSections::class)->handle($website);

        $sections = $website->sections()->get();
        $definitions = app(WebsiteSectionRegistry::class)->all();

        $this->assertSame(array_keys($definitions), $sections->pluck('type')->all());
        $this->assertSame(range(10, 90, 10), $sections->pluck('sort_order')->all());
        $this->assertNotContains(false, $sections->pluck('is_enabled')->all(), true);

        foreach ($sections as $section) {
            $this->assertSame($definitions[$section->type]->defaultContent, $section->content);
        }
    }

    public function test_initialization_is_idempotent_and_preserves_existing_edits_and_unknown_sections(): void
    {
        $website = Website::factory()->create();
        $initializer = app(InitializeWebsiteSections::class);
        $initializer->handle($website);
        $hero = $website->sections()->where('type', 'hero')->sole();
        $hero->update([
            'content' => ['headline' => 'Our day', 'subheadline' => 'Join us'],
            'is_enabled' => false,
            'sort_order' => 7,
        ]);
        $legacy = WebsiteSection::factory()->for($website)->forType('customLegacySection')->create([
            'sort_order' => 15,
            'content' => ['body' => 'Keep me'],
        ]);

        $initializer->handle($website);

        $this->assertDatabaseCount('website_sections', 10);
        $this->assertSame(['headline' => 'Our day', 'subheadline' => 'Join us'], $hero->refresh()->content);
        $this->assertFalse($hero->is_enabled);
        $this->assertSame(7, $hero->sort_order);
        $this->assertSame(['body' => 'Keep me'], $legacy->refresh()->content);
    }

    public function test_initialization_adds_only_a_missing_canonical_section(): void
    {
        $website = Website::factory()->create();
        $initializer = app(InitializeWebsiteSections::class);
        $initializer->handle($website);
        $website->sections()->where('type', 'venue')->delete();

        $initializer->handle($website);

        $this->assertSame(9, $website->sections()->count());
        $venue = $website->sections()->where('type', 'venue')->sole();
        $this->assertSame(50, $venue->sort_order);
        $this->assertSame(app(WebsiteSectionRegistry::class)->get('venue')->defaultContent, $venue->content);
    }

    public function test_create_event_keeps_product_roles_and_builds_the_complete_wedding_foundation(): void
    {
        $creator = User::factory()->create();

        $event = app(CreateEvent::class)->handle($creator, ['name' => 'A Wedding']);

        $this->assertSame(PlatformRole::User, $creator->platform_role);
        $this->assertSame(EventMembershipRole::Owner, $event->memberships()->sole()->role);
        $this->assertSame(9, $event->website->sections()->count());
    }

    public function test_w2_rollout_backfills_a_pre_existing_empty_wedding_website(): void
    {
        Schema::dropIfExists('website_sections');
        Schema::dropIfExists('websites');
        (require database_path('migrations/2026_08_14_000000_create_websites_table.php'))->up();
        (require database_path('migrations/2026_08_14_000001_create_website_sections_table.php'))->up();

        $event = Event::factory()->create();
        $websiteId = (string) Str::ulid();
        DB::table('websites')->insert([
            'id' => $websiteId,
            'event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $website = Website::query()->findOrFail($websiteId);

        (require database_path('migrations/2026_08_14_000002_initialize_wedding_website_sections.php'))->up();

        $sections = $website->sections()->get();
        $this->assertCount(9, $sections);
        $this->assertSame(array_keys(app(WebsiteSectionRegistry::class)->all()), $sections->pluck('type')->all());
        $this->assertCount(9, $sections->pluck('type')->unique());
        $this->assertSame(range(10, 90, 10), $sections->pluck('sort_order')->all());
        $this->assertTrue($sections->every(fn (WebsiteSection $section): bool => Str::isUlid($section->id)));
        $this->assertFalse($sections->contains(fn (WebsiteSection $section): bool => str_contains(json_encode($section->content), $event->name)));
    }

    public function test_database_prevents_duplicate_section_types_but_allows_shared_sort_orders(): void
    {
        $website = Website::factory()->create();
        WebsiteSection::factory()->for($website)->forType('hero')->create(['sort_order' => 10]);
        WebsiteSection::factory()->for($website)->forType('story')->create(['sort_order' => 10]);

        $this->expectException(QueryException::class);

        WebsiteSection::factory()->for($website)->forType('hero')->create(['sort_order' => 20]);
    }
}
