<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Actions\Websites\UpdateWebsiteDesignSettings;
use App\Models\Event;
use App\Models\User;
use App\Models\Website;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WebsiteDesignSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeaders(['Accept' => 'application/json', 'Origin' => 'http://localhost']);
    }

    public function test_explicit_website_initialization_receives_template_default_design_settings(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);

        foreach ([WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1] as $templateKey) {
            $event = app(CreateEvent::class)->handle(User::factory()->create(), ['name' => 'A Wedding']);
            $this->assertSame($resolver->globalDesignDefaults($templateKey), $this->initializeWebsite($event, $templateKey)->design_settings);
        }
    }

    public function test_database_requires_explicit_design_settings_without_a_default(): void
    {
        $event = Event::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('websites')->insert([
            'id' => (string) Str::ulid(),
            'event_id' => $event->id,
            'template_key' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_draft_exposes_design_settings_and_template_options(): void
    {
        [$event, $owner] = $this->eventWithOwner();

        $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")
            ->assertOk()
            ->assertJsonPath('data.designSettings', $this->defaults())
            ->assertJsonPath('data.template.designOptions.colorThemes.0.key', 'terracotta')
            ->assertJsonCount(5, 'data.template.designOptions.colorThemes')
            ->assertJsonCount(3, 'data.template.designOptions.fontSets')
            ->assertJsonCount(4, 'data.template.designOptions.artStyles');
    }

    public function test_design_endpoint_persists_complete_valid_settings_and_preserves_sections(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $before = $event->website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all();
        $settings = ['colorTheme' => 'sage', 'fontSet' => 'romantic', 'artStyle' => 'botanical'];

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/design", ['designSettings' => $settings])
            ->assertOk()->assertJsonPath('data.designSettings', $settings);

        $this->assertSame($settings, $event->website->refresh()->design_settings);
        $this->assertSame($before, $event->website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all());
    }

    public function test_invalid_and_extra_design_values_are_rejected(): void
    {
        $website = Website::factory()->create();
        $action = app(UpdateWebsiteDesignSettings::class);

        foreach ([
            ['colorTheme' => 'neonRainbow', 'fontSet' => 'editorial', 'artStyle' => 'minimal'],
            ['colorTheme' => 'olive', 'fontSet' => 'comicSans', 'artStyle' => 'minimal'],
            ['colorTheme' => 'olive', 'fontSet' => 'modern', 'artStyle' => 'externalUrl'],
            ['colorTheme' => 'olive', 'fontSet' => 'modern'],
            ['colorTheme' => 'olive', 'fontSet' => 'modern', 'artStyle' => 'clean', 'css' => 'body{}'],
        ] as $settings) {
            try {
                $action->handle($website, $settings);
                $this->fail('Invalid design settings should fail.');
            } catch (ValidationException) {
                $this->assertSame($this->defaults(), $website->refresh()->design_settings);
            }
        }
    }

    public function test_each_typed_global_design_control_accepts_and_rejects_values_for_both_templates(): void
    {
        $resolver = app(WebsiteCapabilityResolver::class);

        foreach ([WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, WebsiteTemplateRegistry::MODERN_EDITORIAL_V1] as $templateKey) {
            $owner = User::factory()->create();
            $event = app(CreateEvent::class)->handle($owner, ['name' => 'A Wedding']);
            $website = $this->initializeWebsite($event, $templateKey);
            $settings = $resolver->globalDesignDefaults($templateKey);
            $url = "/api/events/{$event->id}/website/design";

            foreach ($resolver->globalDesign($templateKey)->controls as $control) {
                $settings[$control->id->value] = $control->options[1]['key'];
                $this->actingAs($owner)->putJson($url, ['designSettings' => $settings])
                    ->assertOk()
                    ->assertJsonPath("data.designSettings.{$control->id->value}", $settings[$control->id->value]);

                $invalid = [...$settings, $control->id->value => 'unsupported-value'];
                $this->actingAs($owner)->putJson($url, ['designSettings' => $invalid])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors("designSettings.{$control->id->value}");
                $this->assertSame($settings, $website->refresh()->design_settings);
            }
        }
    }

    public function test_historical_invalid_design_settings_normalize_to_capability_defaults_without_mutation(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $website = $event->website;
        $defaults = app(WebsiteCapabilityResolver::class)->globalDesignDefaults($website->template_key);

        foreach ([
            ['fontSet' => 'editorial', 'artStyle' => 'minimal'],
            ['colorTheme' => 'unknown', 'fontSet' => 'editorial', 'artStyle' => 'minimal'],
            ['colorTheme' => [], 'fontSet' => 42, 'artStyle' => null],
        ] as $stored) {
            DB::table('websites')->where('id', $website->id)->update([
                'design_settings' => json_encode($stored, JSON_THROW_ON_ERROR),
            ]);
            $before = $website->refresh()->design_settings;

            $this->actingAs($owner)->getJson("/api/events/{$event->id}/website")
                ->assertOk()
                ->assertJsonPath('data.schemaVersion', 2)
                ->assertJsonPath('data.designSettings', $defaults)
                ->assertJsonPath('data.template.designOptions', app(WebsiteTemplateRegistry::class)->get($website->template_key)->designOptions)
                ->assertJsonPath('data.template.capabilities.globalDesign.controls.0.default', $defaults['colorTheme']);

            $this->assertSame($before, $website->refresh()->design_settings);
        }
    }

    public function test_design_endpoint_requires_event_access(): void
    {
        [$event, $owner] = $this->eventWithOwner();
        $unrelated = User::factory()->create();
        $payload = ['designSettings' => $this->defaults()];
        $url = "/api/events/{$event->id}/website/design";

        $this->putJson($url, $payload)->assertUnauthorized();
        $this->actingAs($unrelated)->putJson($url, $payload)->assertForbidden();
        $this->actingAs($owner)->putJson($url, $payload)->assertOk();
    }

    public function test_w7_rollout_and_rollback_reapply_preserve_sections(): void
    {
        Schema::dropIfExists('website_sections');
        Schema::dropIfExists('websites');
        $event = Event::factory()->create();
        (require database_path('migrations/2026_08_14_000000_create_websites_table.php'))->up();
        (require database_path('migrations/2026_08_14_000001_create_website_sections_table.php'))->up();
        (require database_path('migrations/2026_08_14_000002_initialize_wedding_website_sections.php'))->up();
        (require database_path('migrations/2026_08_14_000003_assign_default_website_templates.php'))->up();
        $website = Website::query()->where('event_id', $event->id)->sole();
        $before = $website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all();
        $migration = require database_path('migrations/2026_08_15_000000_add_design_settings_to_websites.php');

        $migration->up();
        $this->assertSame($this->defaults(), $website->refresh()->design_settings);
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all());
        $migration->down();
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all());
        $migration->up();
        $this->assertSame($before, $website->sections()->get()->map->only(['id', 'content', 'sort_order', 'is_enabled'])->all());
    }

    /** @return array{Event, User} */
    private function eventWithOwner(): array
    {
        $owner = User::factory()->create();

        $event = app(CreateEvent::class)->handle($owner, ['name' => 'A Wedding']);
        $this->initializeWebsite($event);

        return [$event->refresh(), $owner];
    }

    /** @return array{colorTheme: string, fontSet: string, artStyle: string} */
    private function defaults(): array
    {
        return app(WebsiteTemplateRegistry::class)
            ->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)
            ->defaultDesignSettings;
    }
}
