<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\AssignWebsiteTemplate;
use App\Enums\EventMembershipRole;
use App\Enums\PlatformRole;
use App\Exceptions\IncompatibleWebsiteTemplate;
use App\Exceptions\UnknownWebsiteTemplate;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteSection;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteTemplateAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_compatible_template_can_be_assigned_without_mutating_sections(): void
    {
        $website = Website::factory()->create();
        WebsiteSection::factory()->for($website)->forType('hero')->create([
            'content' => ['headline' => 'Preserve me'],
            'sort_order' => 7,
            'is_enabled' => false,
        ]);
        $before = $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all();

        $assigned = app(AssignWebsiteTemplate::class)->handle(
            $website,
            WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
        );

        $this->assertSame(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, $assigned->template_key);
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all());
    }

    public function test_template_switch_normalizes_presentations_without_remembering_template_state_or_mutating_content(): void
    {
        $website = Website::factory()->create(['template_key' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1]);
        $content = ['heading' => 'Wedding Party', 'groups' => [[
            'id' => 'friends', 'name' => 'Friends', 'people' => [[
                'id' => 'jane', 'name' => 'Jane', 'media' => ['assetId' => (string) Str::ulid(), 'focalPoint' => ['x' => 0.3, 'y' => 0.7]],
            ]],
        ]]];
        $people = WebsiteSection::factory()->for($website)->forType('people')->create([
            'content' => $content,
            'appearance' => [...WebsiteSectionAppearance::DEFAULT, 'presentation' => 'portraitCards'],
        ]);

        app(AssignWebsiteTemplate::class)->handle($website, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $this->assertSame('editorialPortraits', $people->refresh()->appearance['presentation']);
        $this->assertSame($content, $people->content);

        app(AssignWebsiteTemplate::class)->handle($website, WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $this->assertSame('medallions', $people->refresh()->appearance['presentation']);
        $this->assertSame($content, $people->content);
    }

    public function test_template_switch_normalizes_media_styling_for_the_target_presentation(): void
    {
        $website = Website::factory()->create(['template_key' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1]);
        $hero = WebsiteSection::factory()->for($website)->forType('hero')->create([
            'appearance' => [...WebsiteSectionAppearance::DEFAULT, 'presentation' => 'classic', 'mediaPlacement' => 'bottom', 'mediaSize' => 'compact', 'frameStyle' => 'heritage', 'cornerStyle' => 'rounded', 'shadowStyle' => 'soft'],
        ]);

        app(AssignWebsiteTemplate::class)->handle($website, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);

        $appearance = $hero->refresh()->appearance;
        $this->assertSame('immersive', $appearance['presentation']);
        $this->assertSame(0.5, $appearance['overlayStrength']);
        $this->assertSame('#FFFFFF', $appearance['foregroundColor']);
        $this->assertArrayNotHasKey('frameStyle', $appearance);
        $this->assertArrayNotHasKey('mediaPlacement', $appearance);
    }

    public function test_template_switch_preserves_compatible_semantic_media_spacing_and_removes_it_when_unsupported(): void
    {
        $website = Website::factory()->create(['template_key' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1]);
        $spacing = ['top' => 'small', 'right' => 'medium', 'bottom' => 'large', 'left' => 'none'];
        $story = WebsiteSection::factory()->for($website)->forType('story')->create([
            'appearance' => [...WebsiteSectionAppearance::DEFAULT, 'presentation' => 'framed', 'mediaSpacing' => $spacing, 'mediaContentGap' => 'spacious'],
        ]);

        app(AssignWebsiteTemplate::class)->handle($website, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $this->assertSame('textFirst', $story->refresh()->appearance['presentation']);
        $this->assertSame('hairline', $story->appearance['frameStyle']);
        $this->assertSame($spacing, $story->refresh()->appearance['mediaSpacing']);
        $this->assertSame('spacious', $story->appearance['mediaContentGap']);

        $hero = WebsiteSection::factory()->for($website)->forType('hero')->create([
            'appearance' => [...WebsiteSectionAppearance::DEFAULT, 'presentation' => 'editorial', 'mediaSpacing' => $spacing, 'mediaContentGap' => 'generous'],
        ]);
        app(AssignWebsiteTemplate::class)->handle($website, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $hero->update(['appearance' => [...$hero->appearance, 'presentation' => 'immersive']]);
        app(AssignWebsiteTemplate::class)->handle($website, WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
        $this->assertArrayNotHasKey('mediaSpacing', $hero->refresh()->appearance);
        $this->assertArrayNotHasKey('mediaContentGap', $hero->appearance);
    }

    public function test_unknown_template_is_rejected_without_changing_assignment(): void
    {
        $website = Website::factory()->create();
        $originalKey = $website->template_key;

        try {
            app(AssignWebsiteTemplate::class)->handle($website, 'unknown-template');
            $this->fail('Unknown template assignment should fail.');
        } catch (UnknownWebsiteTemplate) {
            $this->assertSame($originalKey, $website->refresh()->template_key);
        }
    }

    public function test_enabled_unsupported_section_blocks_assignment(): void
    {
        $website = Website::factory()->create();
        WebsiteSection::factory()->for($website)->forType('customLegacySection')->create(['is_enabled' => true]);

        $this->expectException(IncompatibleWebsiteTemplate::class);

        app(AssignWebsiteTemplate::class)->handle($website, WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);
    }

    public function test_disabled_unsupported_section_does_not_block_assignment(): void
    {
        $website = Website::factory()->create();
        $legacy = WebsiteSection::factory()->for($website)->forType('customLegacySection')->disabled()->create();

        app(AssignWebsiteTemplate::class)->handle($website, WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1);

        $this->assertSame(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, $website->refresh()->template_key);
        $this->assertDatabaseHas('website_sections', ['id' => $legacy->id, 'is_enabled' => false]);
    }

    public function test_explicit_initialization_assigns_requested_template_with_sections_and_owner_membership(): void
    {
        $creator = User::factory()->create();

        $event = app(CreateEvent::class)->handle($creator, ['name' => 'A Wedding']);
        $this->initializeWebsite($event);

        $this->assertSame(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, $event->website->template_key);
        $this->assertSame(10, $event->website->sections()->count());
        $this->assertSame(EventMembershipRole::Owner, $event->memberships()->sole()->role);
        $this->assertSame(PlatformRole::User, $creator->platform_role);
    }

    public function test_database_does_not_supply_a_template_when_raw_website_insert_omits_it(): void
    {
        $event = Event::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('websites')->insert([
            'id' => (string) Str::ulid(),
            'event_id' => $event->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_w3_rollout_assigns_template_without_changing_existing_sections(): void
    {
        Schema::dropIfExists('website_sections');
        Schema::dropIfExists('websites');

        $event = Event::factory()->create();
        (require database_path('migrations/2026_08_14_000000_create_websites_table.php'))->up();
        (require database_path('migrations/2026_08_14_000001_create_website_sections_table.php'))->up();
        (require database_path('migrations/2026_08_14_000002_initialize_wedding_website_sections.php'))->up();

        $website = Website::query()->where('event_id', $event->id)->sole();
        $hero = $website->sections()->where('type', 'hero')->sole();
        $hero->update([
            'content' => ['headline' => 'Existing copy', 'subheadline' => 'Still here'],
            'sort_order' => 3,
            'is_enabled' => false,
        ]);
        $before = $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all();

        $migration = require database_path('migrations/2026_08_14_000003_assign_default_website_templates.php');
        $migration->up();

        $this->assertSame(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, $website->refresh()->template_key);
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all());
        $this->assertSame(9, $website->sections()->count());

        $migration->down();
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all());

        $migration->up();
        $this->assertSame(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, $website->refresh()->template_key);
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content'])->all());
    }
}
