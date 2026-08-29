<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteSchema;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteTemplateRegistry;
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

    public function test_media_styling_is_presentation_scoped_and_historical_appearance_remains_valid(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/appearance";
        $base = WebsiteSectionAppearance::DEFAULT;

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()
            ->assertJsonPath('data.sections.0.presentationCapability.options.0.mediaControls.mediaPlacements.default', 'top')
            ->assertJsonPath('data.sections.0.presentationCapability.options.0.mediaControls.mediaPlacements.options.0.key', 'top')
            ->assertJsonPath('data.sections.0.presentationCapability.options.0.mediaControls.mediaPlacements.options.1.key', 'right')
            ->assertJsonPath('data.sections.0.presentationCapability.options.0.mediaControls.mediaPlacements.options.2.key', 'bottom')
            ->assertJsonPath('data.sections.0.presentationCapability.options.0.mediaControls.mediaPlacements.options.3.key', 'left')
            ->assertJsonPath('data.sections.0.presentationCapability.options.0.mediaControls.responsive.tablet.mediaPlacement.default', 'top')
            ->assertJsonPath('data.sections.0.presentationCapability.options.0.mediaControls.responsive.tablet.mediaPlacement.options.0.key', 'top')
            ->assertJsonPath('data.sections.0.presentationCapability.options.0.mediaControls.responsive.tablet.mediaPlacement.options.1.key', 'bottom')
            ->assertJsonPath('data.sections.0.presentationCapability.options.0.mediaControls.responsive.mobile.mediaPlacement.default', 'top')
            ->assertJsonPath('data.sections.0.presentationCapability.options.0.mediaControls.responsive.mobile.mediaPlacement.options.1.key', 'bottom')
            ->assertJsonPath('data.sections.0.presentationCapability.options.1.mediaControls.overlayStrength.default', 0.5)
            ->assertJsonPath('data.sections.0.presentationCapability.options.1.mediaControls.frameStyles', null);

        foreach (['top', 'right', 'bottom', 'left'] as $placement) {
            $styled = [...$base, 'presentation' => 'classic', 'mediaPlacement' => $placement, 'mediaSize' => 'compact', 'frameStyle' => 'heritage', 'cornerStyle' => 'soft', 'shadowStyle' => 'elevated'];
            $this->actingAs($owner)->putJson($url, ['appearance' => $styled])->assertOk();
            $this->assertSame($styled, $hero->refresh()->appearance);
        }

        foreach ([
            [...$base, 'presentation' => 'immersive', 'frameStyle' => 'fineLine'],
            [...$base, 'presentation' => 'classic', 'mediaPlacement' => 'diagonal'],
            [...$base, 'presentation' => 'immersive', 'overlayStrength' => 0.9],
            [...$base, 'presentation' => 'immersive', 'foregroundColor' => '#FF00FF'],
        ] as $appearance) {
            $this->actingAs($owner)->putJson($url, compact('appearance'))->assertUnprocessable();
        }

        $immersive = [...$base, 'presentation' => 'immersive', 'overlayStrength' => 0.6, 'foregroundColor' => '#FFFFFF'];
        $this->actingAs($owner)->putJson($url, ['appearance' => $immersive])->assertOk();
        $this->assertSame($immersive, $hero->refresh()->appearance);

        $this->actingAs($owner)->putJson($url, ['appearance' => $base])->assertOk();
        $this->assertSame($base, $hero->refresh()->appearance);
    }

    public function test_semantic_media_spacing_and_content_gap_are_strict_and_presentation_scoped(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $story = $event->website->sections()->where('type', 'hero')->sole();
        $url = "/api/events/{$event->id}/website/sections/{$story->id}/appearance";
        $base = WebsiteSectionAppearance::DEFAULT;
        $spacing = ['top' => 'none', 'right' => 'small', 'bottom' => 'medium', 'left' => 'large'];
        $appearance = [...$base, 'presentation' => 'classic', 'mediaSpacing' => $spacing, 'mediaContentGap' => 'spacious'];

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()
            ->assertJsonPath('data.sections.0.presentationCapability.options.0.mediaControls.mediaSpacing.default.top', 'medium')
            ->assertJsonPath('data.sections.0.presentationCapability.options.0.mediaControls.mediaContentGaps.default', 'comfortable');
        $this->actingAs($owner)->putJson($url, compact('appearance'))->assertOk();
        $this->assertSame($appearance, $story->refresh()->appearance);

        $linked = [...$base, 'presentation' => 'classic', 'mediaSpacing' => array_fill_keys(['top', 'right', 'bottom', 'left'], 'large'), 'mediaContentGap' => 'comfortable'];
        $this->actingAs($owner)->putJson($url, ['appearance' => $linked])->assertOk();

        foreach ([
            [...$appearance, 'mediaSpacing' => [...$spacing, 'top' => 'huge']],
            [...$appearance, 'mediaSpacing' => [...$spacing, 'inside' => 'small']],
            [...$appearance, 'mediaSpacing' => ['top' => 'small']],
            [...$appearance, 'mediaSpacing' => 'medium'],
            [...$appearance, 'mediaContentGap' => 'enormous'],
            [...$base, 'presentation' => 'immersive', 'mediaSpacing' => $spacing],
            [...$base, 'presentation' => 'immersive', 'mediaContentGap' => 'comfortable'],
        ] as $invalid) {
            $this->actingAs($owner)->putJson($url, ['appearance' => $invalid])->assertUnprocessable();
        }

        $this->actingAs($owner)->putJson($url, ['appearance' => $base])->assertOk();
    }

    public function test_story_advertises_only_current_authoring_controls_and_preserves_legacy_storage(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $story = $event->website->sections()->where('type', 'story')->sole();
        $legacy = [...WebsiteSectionAppearance::DEFAULT, 'emphasis' => 'featured', 'presentation' => 'portraitStory', 'mediaPlacement' => 'left', 'mediaSize' => 'feature'];
        $story->update(['appearance' => $legacy]);

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()
            ->assertJsonPath('data.sections.2.presentationCapability', null)
            ->assertJsonPath('data.sections.2.appearance.emphasis', 'inherit')
            ->assertJsonMissingPath('data.sections.2.appearance.presentation')
            ->assertJsonMissingPath('data.sections.2.appearance.mediaPlacement');

        $appearance = [...WebsiteSectionAppearance::DEFAULT, 'backgroundTreatment' => 'soft'];
        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$story->id}/appearance", compact('appearance'))->assertOk();
        $stored = $story->refresh()->appearance;
        $this->assertSame('soft', $stored['backgroundTreatment']);
        $this->assertSame('featured', $stored['emphasis']);
        $this->assertSame('portraitStory', $stored['presentation']);
        $this->assertSame('left', $stored['mediaPlacement']);
        $this->assertSame('feature', $stored['mediaSize']);
    }

    public function test_sparse_responsive_overrides_round_trip_without_changing_desktop_or_content(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $content = $hero->content;
        $spacing = ['top' => 'none', 'right' => 'small', 'bottom' => 'medium', 'left' => 'large'];
        $appearance = [
            ...WebsiteSectionAppearance::DEFAULT,
            'presentation' => 'classic',
            'mediaPlacement' => 'left',
            'mediaSize' => 'balanced',
            'frameStyle' => 'none',
            'cornerStyle' => 'square',
            'shadowStyle' => 'none',
            'mediaSpacing' => array_fill_keys(['top', 'right', 'bottom', 'left'], 'medium'),
            'mediaContentGap' => 'comfortable',
            'responsive' => [
                'tablet' => ['headingAlignment' => 'left'],
                'mobile' => ['mediaPlacement' => 'bottom', 'mediaSize' => 'feature', 'mediaSpacing' => $spacing],
            ],
        ];
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/appearance";

        $this->actingAs($owner)->putJson($url, compact('appearance'))->assertOk()
            ->assertJsonPath('data.sections.0.appearance', $appearance);
        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()
            ->assertJsonPath('data.sections.0.appearance.responsive.tablet.headingAlignment', 'left')
            ->assertJsonPath('data.sections.0.appearance.responsive.mobile.mediaPlacement', 'bottom');

        $this->assertSame('left', $hero->refresh()->appearance['mediaPlacement']);
        $this->assertSame($content, $hero->content);
    }

    public function test_default_responsive_values_are_removed_while_non_default_viewport_intent_is_preserved(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $appearance = [
            ...WebsiteSectionAppearance::DEFAULT,
            'presentation' => 'classic',
            'mediaPlacement' => 'left',
            'mediaSize' => 'feature',
            'responsive' => [
                'tablet' => ['mediaPlacement' => 'top', 'mediaSize' => 'balanced', 'headingAlignment' => 'inherit'],
                'mobile' => ['mediaPlacement' => 'bottom', 'mediaSize' => 'balanced'],
            ],
        ];

        $response = $this->actingAs($owner)->putJson(
            "/api/events/{$event->id}/websites/{$event->website->id}/sections/{$hero->id}/appearance",
            compact('appearance'),
        )->assertOk();

        $expected = [
            ...WebsiteSectionAppearance::DEFAULT,
            'presentation' => 'classic',
            'mediaPlacement' => 'left',
            'mediaSize' => 'feature',
            'responsive' => ['mobile' => ['mediaPlacement' => 'bottom']],
        ];
        $response->assertJsonPath('data.schemaVersion', WebsiteSchema::CURRENT_SCHEMA_VERSION)
            ->assertJsonPath('data.sections.0.appearance', $expected);
        $this->assertSame($expected, $hero->refresh()->appearance);
    }

    public function test_resolver_serialized_tablet_defaults_are_the_write_pruning_defaults_for_both_templates(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);

        foreach ([WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1] as $templateKey) {
            $owner = User::factory()->create();
            $event = app(CreateEvent::class)->handle($owner, ['name' => "{$templateKey} default parity"]);
            $website = $this->initializeWebsite($event, $templateKey);
            $hero = $website->sections()->where('type', 'hero')->sole();
            $presentation = $resolver->section($templateKey, 'hero')->defaultPresentation;
            $tabletControl = collect($resolver->controlsForViewport($templateKey, 'hero', $presentation, 'tablet'))->keyBy('id')['headingAlignment'];
            $appearance = [
                ...WebsiteSectionAppearance::DEFAULT,
                'presentation' => $presentation,
                'responsive' => ['tablet' => ['headingAlignment' => $tabletControl->default]],
            ];

            $this->actingAs($owner)->putJson(
                "/api/events/{$event->id}/websites/{$website->id}/sections/{$hero->id}/appearance",
                compact('appearance'),
            )->assertOk()
                ->assertJsonPath('data.schemaVersion', WebsiteSchema::CURRENT_SCHEMA_VERSION)
                ->assertJsonMissingPath('data.sections.0.appearance.responsive');

            $this->assertArrayNotHasKey('responsive', $hero->refresh()->appearance);
        }
    }

    public function test_canonicalizing_default_overrides_removes_empty_responsive_object_on_legacy_route(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $appearance = [
            ...WebsiteSectionAppearance::DEFAULT,
            'presentation' => 'classic',
            'responsive' => [
                'tablet' => ['mediaPlacement' => 'top'],
                'mobile' => ['mediaPlacement' => 'top'],
            ],
        ];

        $this->actingAs($owner)->putJson(
            "/api/events/{$event->id}/website/sections/{$hero->id}/appearance",
            compact('appearance'),
        )->assertOk()->assertJsonMissingPath('data.sections.0.appearance.responsive');

        $this->assertArrayNotHasKey('responsive', $hero->refresh()->appearance);
    }

    public function test_modern_project_updates_use_resolved_presentation_and_viewport_capabilities(): void
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => 'A Modern Wedding']);
        $website = $this->initializeWebsite($event, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1);
        $hero = $website->sections()->where('type', 'hero')->sole();
        $url = "/api/events/{$event->id}/websites/{$website->id}/sections/{$hero->id}/appearance";
        $appearance = [
            ...WebsiteSectionAppearance::DEFAULT,
            'presentation' => 'editorial',
            'mediaPlacement' => 'left',
            'responsive' => ['tablet' => ['mediaPlacement' => 'bottom']],
        ];

        $this->actingAs($owner)->putJson($url, compact('appearance'))->assertOk()
            ->assertJsonPath('data.sections.0.appearance.responsive.tablet.mediaPlacement', 'bottom');
        $this->actingAs($owner)->putJson($url, ['appearance' => [
            ...WebsiteSectionAppearance::DEFAULT,
            'presentation' => 'classic',
        ]])->assertUnprocessable()->assertJsonValidationErrors('appearance.presentation');
        $this->actingAs($owner)->putJson($url, ['appearance' => [
            ...WebsiteSectionAppearance::DEFAULT,
            'presentation' => 'editorial',
            'responsive' => ['mobile' => ['mediaPlacement' => 'left']],
        ]])->assertUnprocessable()->assertJsonValidationErrors('appearance.responsive.mobile.mediaPlacement');

        $this->assertSame($appearance, $hero->refresh()->appearance);
    }

    public function test_responsive_overrides_reject_unknown_keys_and_unsupported_values(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/appearance";
        $base = [...WebsiteSectionAppearance::DEFAULT, 'presentation' => 'classic'];

        foreach ([
            [...$base, 'responsive' => ['watch' => ['mediaPlacement' => 'top']]],
            [...$base, 'responsive' => ['mobile' => ['frameStyle' => 'heritage']]],
            [...$base, 'responsive' => ['mobile' => ['mediaPlacement' => 'diagonal']]],
            [...$base, 'responsive' => ['mobile' => ['mediaSpacing' => ['top' => 'none']]]],
        ] as $appearance) {
            $this->actingAs($owner)->putJson($url, compact('appearance'))->assertUnprocessable();
        }

        $this->assertSame(WebsiteSectionAppearance::DEFAULT, $hero->refresh()->appearance);
    }

    public function test_tablet_media_placement_accepts_vertical_and_rejects_horizontal_values(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $hero = $event->website->sections()->where('type', 'hero')->sole();
        $url = "/api/events/{$event->id}/website/sections/{$hero->id}/appearance";
        $base = [...WebsiteSectionAppearance::DEFAULT, 'presentation' => 'classic', 'mediaPlacement' => 'right'];

        foreach (['top', 'bottom'] as $placement) {
            $appearance = [...$base, 'responsive' => ['tablet' => ['mediaPlacement' => $placement]]];
            $response = $this->actingAs($owner)->putJson($url, compact('appearance'))->assertOk();
            $placement === 'top'
                ? $response->assertJsonMissingPath('data.sections.0.appearance.responsive')
                : $response->assertJsonPath('data.sections.0.appearance.responsive.tablet.mediaPlacement', $placement);
        }

        foreach (['left', 'right'] as $placement) {
            $appearance = [...$base, 'responsive' => ['tablet' => ['mediaPlacement' => $placement]]];
            $this->actingAs($owner)->putJson($url, compact('appearance'))->assertUnprocessable();
        }
    }

    public function test_story_rejects_new_section_level_presentation_and_media_authoring(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $story = $event->website->sections()->where('type', 'story')->sole();
        $url = "/api/events/{$event->id}/website/sections/{$story->id}/appearance";
        $base = WebsiteSectionAppearance::DEFAULT;
        $this->actingAs($owner)->putJson($url, ['appearance' => [...$base, 'presentation' => 'portraitStory']])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['appearance' => [...$base, 'mediaPlacement' => 'left']])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['appearance' => [...$base, 'responsive' => ['tablet' => ['mediaPlacement' => 'left']]]])->assertUnprocessable();
        $this->actingAs($owner)->putJson($url, ['appearance' => [...$base, 'emphasis' => 'featured']])->assertUnprocessable();
    }

    public function test_classic_venue_accepts_all_tablet_placements_and_draft_exposes_the_capability(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $venue = $event->website->sections()->where('type', 'venue')->sole();
        $url = "/api/events/{$event->id}/website/sections/{$venue->id}/appearance";
        $base = [...WebsiteSectionAppearance::DEFAULT, 'presentation' => 'detailsFirst', 'mediaPlacement' => 'right'];

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()
            ->assertJsonPath('data.sections.4.presentationCapability.options.0.mediaControls.responsive.tablet.mediaPlacement.default', 'top')
            ->assertJsonPath('data.sections.4.presentationCapability.options.0.mediaControls.responsive.tablet.mediaPlacement.options.0.key', 'top')
            ->assertJsonPath('data.sections.4.presentationCapability.options.0.mediaControls.responsive.tablet.mediaPlacement.options.1.key', 'bottom')
            ->assertJsonPath('data.sections.4.presentationCapability.options.0.mediaControls.responsive.tablet.mediaPlacement.options.2.key', 'left')
            ->assertJsonPath('data.sections.4.presentationCapability.options.0.mediaControls.responsive.tablet.mediaPlacement.options.3.key', 'right');

        foreach (['top', 'bottom', 'left', 'right'] as $placement) {
            $appearance = [...$base, 'responsive' => ['tablet' => ['mediaPlacement' => $placement]]];
            $response = $this->actingAs($owner)->putJson($url, compact('appearance'))->assertOk();
            $placement === 'top'
                ? $response->assertJsonMissingPath('data.sections.4.appearance.responsive')
                : $response->assertJsonPath('data.sections.4.appearance.responsive.tablet.mediaPlacement', $placement);
        }

        foreach (['left', 'right'] as $placement) {
            $appearance = [...$base, 'responsive' => ['mobile' => ['mediaPlacement' => $placement]]];
            $this->actingAs($owner)->putJson($url, compact('appearance'))->assertUnprocessable();
        }
    }

    public function test_historical_framed_appearance_is_resolved_without_mutation_and_new_framed_updates_are_rejected(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $story = $event->website->sections()->where('type', 'story')->sole();
        $content = ['heading' => 'Our beginning', 'body' => 'Semantic content remains unchanged.'];
        $spacing = ['top' => 'none', 'right' => 'small', 'bottom' => 'medium', 'left' => 'large'];
        $legacy = [
            ...WebsiteSectionAppearance::DEFAULT,
            'presentation' => 'framed',
            'cornerStyle' => 'rounded',
            'shadowStyle' => 'soft',
            'mediaSpacing' => $spacing,
            'mediaContentGap' => 'spacious',
        ];
        $story->update(['appearance' => $legacy, 'content' => $content]);

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")->assertOk()
            ->assertJsonMissingPath('data.sections.2.appearance.presentation')
            ->assertJsonMissingPath('data.sections.2.appearance.frameStyle')
            ->assertJsonMissingPath('data.sections.2.appearance.cornerStyle')
            ->assertJsonMissingPath('data.sections.2.appearance.shadowStyle')
            ->assertJsonMissingPath('data.sections.2.appearance.mediaSpacing')
            ->assertJsonMissingPath('data.sections.2.appearance.mediaContentGap')
            ->assertJsonPath('data.sections.2.content.heading', 'Our beginning')
            ->assertJsonPath('data.sections.2.content.elements.0.id', 'story-legacy-'.$story->id)
            ->assertJsonPath('data.sections.2.content.elements.0.slots.body.text', 'Semantic content remains unchanged.');

        $this->assertSame($legacy, $story->refresh()->appearance);
        $this->assertSame($content, $story->content);

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$story->id}/appearance", [
            'appearance' => $legacy,
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
