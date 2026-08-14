<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Enums\EventMembershipRole;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteSection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteDomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_w1_rollout_backfills_one_empty_website_for_a_pre_existing_event(): void
    {
        Schema::dropIfExists('website_sections');
        Schema::dropIfExists('websites');

        $event = Event::factory()->create();

        $websiteMigration = require database_path('migrations/2026_08_14_000000_create_websites_table.php');
        $websiteMigration->up();
        $sectionMigration = require database_path('migrations/2026_08_14_000001_create_website_sections_table.php');
        $sectionMigration->up();

        $website = Website::query()->where('event_id', $event->id)->sole();

        $this->assertTrue(Str::isUlid($website->id));
        $this->assertDatabaseCount('websites', 1);
        $this->assertDatabaseCount('website_sections', 0);
    }

    public function test_event_has_one_website_and_website_belongs_to_event(): void
    {
        $event = Event::factory()->create();
        $website = Website::factory()->for($event)->create();

        $this->assertTrue($event->website->is($website));
        $this->assertTrue($website->event->is($event));
        $this->assertTrue(Str::isUlid($website->id));
    }

    public function test_database_prevents_more_than_one_website_for_an_event(): void
    {
        $website = Website::factory()->create();

        $this->expectException(QueryException::class);

        Website::factory()->create(['event_id' => $website->event_id]);
    }

    public function test_website_sections_preserve_semantic_content_and_cast_domain_state(): void
    {
        $website = Website::factory()->create();
        $section = $website->sections()->create([
            'type' => 'hero',
            'sort_order' => 3,
            'is_enabled' => false,
            'content' => [
                'heading' => 'Neil & Hazel',
                'subheading' => 'December 22, 2026',
            ],
        ])->refresh();

        $this->assertTrue(Str::isUlid($section->id));
        $this->assertTrue($section->website->is($website));
        $this->assertSame('hero', $section->type);
        $this->assertSame(3, $section->sort_order);
        $this->assertFalse($section->is_enabled);
        $this->assertSame([
            'heading' => 'Neil & Hazel',
            'subheading' => 'December 22, 2026',
        ], $section->content);
    }

    public function test_section_defaults_preserve_enabled_state_and_empty_content(): void
    {
        $section = Website::factory()->create()->sections()->create([
            'type' => 'story',
            'sort_order' => 1,
        ])->refresh();

        $this->assertTrue($section->is_enabled);
        $this->assertSame([], $section->content);
    }

    public function test_sections_are_retrieved_by_sort_order_with_id_as_a_deterministic_fallback(): void
    {
        $website = Website::factory()->create();
        $later = WebsiteSection::factory()->for($website)->forType('hero')->create(['sort_order' => 20]);
        $sameOrderFirst = WebsiteSection::factory()->for($website)->forType('story')->create(['sort_order' => 10]);
        $sameOrderSecond = WebsiteSection::factory()->for($website)->forType('venue')->create(['sort_order' => 10]);

        $expectedSameOrder = collect([$sameOrderFirst->id, $sameOrderSecond->id])->sort()->values()->all();

        $this->assertSame([
            ...$expectedSameOrder,
            $later->id,
        ], $website->sections->pluck('id')->all());
    }

    public function test_create_event_atomically_creates_owner_membership_website_and_default_sections(): void
    {
        $creator = User::factory()->create();

        $event = app(CreateEvent::class)->handle($creator, ['name' => 'Neil & Hazel']);

        $this->assertDatabaseCount('websites', 1);
        $this->assertTrue($event->website->event->is($event));
        $this->assertSame([
            'hero',
            'date',
            'story',
            'schedule',
            'venue',
            'dressCode',
            'gallery',
            'faq',
            'rsvp',
        ], $event->website->sections->pluck('type')->all());
        $this->assertSame(EventMembershipRole::Owner, $event->memberships()->sole()->role);
    }

    public function test_deleting_event_cascades_to_website_and_sections(): void
    {
        $event = Event::factory()->create();
        $website = Website::factory()->for($event)->create();
        $section = WebsiteSection::factory()->for($website)->create();

        $event->delete();

        $this->assertDatabaseMissing('websites', ['id' => $website->id]);
        $this->assertDatabaseMissing('website_sections', ['id' => $section->id]);
    }
}
