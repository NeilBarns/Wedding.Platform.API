<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Website\WebsiteSectionAppearance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteSectionAppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_new_sections_receive_explicit_default_appearance(): void
    {
        $event = app(CreateEvent::class)->handle(User::factory()->create(), ['name' => 'A Wedding']);
        $this->initializeWebsite($event);

        $this->assertCount(10, $event->website->sections);
        $event->website->sections->each(fn ($section) => $this->assertSame(WebsiteSectionAppearance::DEFAULT, $section->appearance));
    }

    public function test_database_requires_appearance_without_a_default(): void
    {
        $website = Website::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('website_sections')->insert([
            'id' => (string) Str::ulid(),
            'website_id' => $website->id,
            'type' => 'hero',
            'sort_order' => 1,
            'is_enabled' => true,
            'content' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_draft_exposes_appearance_and_template_owned_options(): void
    {
        [$event, $owner] = $this->eventWithOwner();

        $response = $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk();
        $response->assertJsonPath('data.sections.0.appearance', WebsiteSectionAppearance::DEFAULT)
            ->assertJsonPath('data.sections.0.appearanceOptions.headingAlignments.0.key', 'inherit')
            ->assertJsonCount(4, 'data.sections.0.appearanceOptions.headingAlignments')
            ->assertJsonCount(4, 'data.sections.0.appearanceOptions.bodyAlignments')
            ->assertJsonCount(4, 'data.sections.0.appearanceOptions.backgroundTreatments')
            ->assertJsonCount(4, 'data.sections.0.appearanceOptions.emphasisOptions')
            ->assertJsonPath('data.sections.0.presentationCapability.default', 'classic')
            ->assertJsonPath('data.sections.0.presentationCapability.options.1.key', 'immersive')
            ->assertJsonPath('data.sections.1.presentationCapability', null);
    }

    public function test_valid_update_returns_authoritative_draft_and_preserves_section_data(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $section = $event->website->sections()->where('type', 'venue')->firstOrFail();
        $before = $section->only(['content', 'sort_order', 'is_enabled']);
        $appearance = [
            'headingAlignment' => 'right',
            'bodyAlignment' => 'left',
            'backgroundTreatment' => 'accent',
            'emphasis' => 'featured',
        ];

        $this->actingAs($owner)
            ->putJson("/api/events/{$event->id}/website/sections/{$section->id}/appearance", compact('appearance'))
            ->assertOk()
            ->assertJsonPath('data.sections.4.appearance', $appearance);

        $this->assertSame($appearance, $section->refresh()->appearance);
        $this->assertSame($before, $section->only(['content', 'sort_order', 'is_enabled']));
    }

    public function test_presentation_is_optional_template_defined_and_strictly_validated(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $faq = $event->website->sections()->where('type', 'faq')->sole();
        $base = WebsiteSectionAppearance::DEFAULT;

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}/appearance", [
            'appearance' => [...$base, 'presentation' => 'immersive'],
        ])->assertOk()->assertJsonPath('data.sections.0.appearance.presentation', 'immersive');

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}/appearance", [
            'appearance' => $base,
        ])->assertOk()->assertJsonMissingPath('data.sections.0.appearance.presentation');

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$hero->id}/appearance", [
            'appearance' => [...$base, 'presentation' => 'editorial'],
        ])->assertUnprocessable()->assertJsonValidationErrors('appearance.presentation');

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$faq->id}/appearance", [
            'appearance' => [...$base, 'presentation' => 'anything'],
        ])->assertUnprocessable()->assertJsonValidationErrors('appearance.presentation');
    }

    public function test_invalid_missing_and_extra_appearance_values_are_rejected(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $section = $event->website->sections()->firstOrFail();
        $url = "/api/events/{$event->id}/website/sections/{$section->id}/appearance";
        $invalid = [
            ['headingAlignment' => 'diagonal', 'bodyAlignment' => 'inherit', 'backgroundTreatment' => 'inherit', 'emphasis' => 'inherit'],
            ['headingAlignment' => 'inherit', 'bodyAlignment' => 'justify-everything', 'backgroundTreatment' => 'inherit', 'emphasis' => 'inherit'],
            ['headingAlignment' => 'inherit', 'bodyAlignment' => 'inherit', 'backgroundTreatment' => '#ff0000', 'emphasis' => 'inherit'],
            ['headingAlignment' => 'inherit', 'bodyAlignment' => 'inherit', 'backgroundTreatment' => 'inherit', 'emphasis' => 'huge'],
            ['headingAlignment' => 'inherit', 'bodyAlignment' => 'inherit', 'backgroundTreatment' => 'inherit'],
            [...WebsiteSectionAppearance::DEFAULT, 'customCss' => 'body{}'],
        ];

        foreach ($invalid as $appearance) {
            $this->actingAs($owner)->putJson($url, compact('appearance'))->assertUnprocessable();
            $this->assertSame(WebsiteSectionAppearance::DEFAULT, $section->refresh()->appearance);
        }
    }

    public function test_endpoint_requires_event_access_and_scopes_section_to_event(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        [$otherEvent] = $this->eventWithOwner();
        $section = $event->website->sections()->firstOrFail();
        $otherSection = $otherEvent->website->sections()->firstOrFail();
        $payload = ['appearance' => WebsiteSectionAppearance::DEFAULT];
        $url = "/api/events/{$event->id}/website/sections/{$section->id}/appearance";

        $this->putJson($url, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->putJson($url, $payload)->assertForbidden();
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$otherSection->id}/appearance", $payload)->assertNotFound();
    }

    public function test_w9_rollout_and_rollback_reapply_preserve_existing_sections(): void
    {
        Schema::dropIfExists('website_sections');
        Schema::dropIfExists('websites');
        $event = Event::factory()->create();
        (require database_path('migrations/2026_08_14_000000_create_websites_table.php'))->up();
        (require database_path('migrations/2026_08_14_000001_create_website_sections_table.php'))->up();
        (require database_path('migrations/2026_08_14_000002_initialize_wedding_website_sections.php'))->up();
        (require database_path('migrations/2026_08_14_000003_assign_default_website_templates.php'))->up();
        (require database_path('migrations/2026_08_15_000000_add_design_settings_to_websites.php'))->up();
        $website = Website::query()->where('event_id', $event->id)->sole();
        $before = $this->sectionSnapshot($website->id);
        $migration = require database_path('migrations/2026_08_15_000001_add_appearance_to_website_sections.php');

        $migration->up();
        $website->sections()->get()->each(fn ($section) => $this->assertSame(WebsiteSectionAppearance::DEFAULT, $section->appearance));
        $this->assertSame($before, $this->sectionSnapshot($website->id));
        $migration->down();
        $this->assertSame($before, $this->sectionSnapshot($website->id));
        $migration->up();
        $this->assertSame($before, $this->sectionSnapshot($website->id));
    }

    /** @return array{Event, User} */
    private function eventWithOwner(): array
    {
        $owner = User::factory()->create();

        $event = app(CreateEvent::class)->handle($owner, ['name' => 'A Wedding']);
        $this->initializeWebsite($event);

        return [$event->refresh(), $owner];
    }

    /** @return array<int, array<string, mixed>> */
    private function sectionSnapshot(string $websiteId): array
    {
        return DB::table('website_sections')->where('website_id', $websiteId)->orderBy('sort_order')
            ->get(['id', 'content', 'sort_order', 'is_enabled', 'created_at', 'updated_at'])
            ->map(fn ($row): array => (array) $row)->all();
    }
}
