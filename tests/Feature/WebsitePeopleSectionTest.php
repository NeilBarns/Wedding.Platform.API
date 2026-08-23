<?php

namespace Tests\Feature;

use App\Actions\Events\CreateEvent;
use App\Models\Event;
use App\Models\User;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class WebsitePeopleSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_wedding_website_has_enabled_people_defaults_and_explicit_template_appearance_support(): void
    {
        [$event] = $this->eventWithWebsite();
        $people = $event->website->sections()->where('type', 'people')->sole();
        $templates = app(WebsiteTemplateRegistry::class);

        $this->assertSame('Wedding Party', $people->content['heading']);
        $this->assertSame([], $people->content['groups']);
        $this->assertSame(65, $people->sort_order);
        $this->assertTrue($people->is_enabled);
        $this->assertSame(WebsiteSectionAppearance::DEFAULT, $people->appearance);
        $this->assertTrue($templates->supportsSection(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1, 'people'));
        $this->assertTrue($templates->supportsSection(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1, 'people'));
        $this->assertSame(['itemType' => 'person', 'mode' => 'single'], $templates->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)->itemMediaCapabilityFor('people'));
        $this->assertSame(['itemType' => 'person', 'mode' => 'single'], $templates->get(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1)->itemMediaCapabilityFor('people'));
        $this->assertSame(WebsiteSectionAppearance::DEFAULT, $templates->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)->appearanceDefaultsFor('people'));
        $this->assertSame(WebsiteSectionAppearance::DEFAULT, $templates->get(WebsiteTemplateRegistry::MODERN_EDITORIAL_V1)->appearanceDefaultsFor('people'));
    }

    public function test_explicit_sync_adds_only_missing_people_disabled_and_is_idempotent(): void
    {
        [$event] = $this->eventWithWebsite();
        $website = $event->website;
        $hero = $website->sections()->where('type', 'hero')->sole();
        $hero->update([
            'content' => ['headline' => 'Preserve this', 'subheadline' => 'Exactly'],
            'sort_order' => 7,
            'is_enabled' => false,
            'appearance' => [...WebsiteSectionAppearance::DEFAULT, 'emphasis' => 'featured'],
        ]);
        $website->sections()->where('type', 'people')->delete();
        $before = $website->sections()->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content', 'appearance'])->all();

        Artisan::call('websites:sync-sections');
        Artisan::call('websites:sync-sections');

        $this->assertSame($before, $website->sections()->where('type', '!=', 'people')->get()->map->only(['id', 'type', 'sort_order', 'is_enabled', 'content', 'appearance'])->all());
        $people = $website->sections()->where('type', 'people')->sole();
        $this->assertFalse($people->is_enabled);
        $this->assertSame(['heading' => 'Wedding Party', 'groups' => []], $people->content);
        $this->assertSame(WebsiteSectionAppearance::DEFAULT, $people->appearance);
        $this->assertSame(10, $website->sections()->count());
    }

    public function test_people_content_persists_order_optional_roles_and_empty_groups(): void
    {
        [$event, $owner] = $this->eventWithWebsite();
        $people = $event->website->sections()->where('type', 'people')->sole();
        $content = [
            'heading' => 'Our People',
            'groups' => [
                ['id' => 'group-sponsors', 'name' => 'Principal Sponsors', 'people' => [
                    ['id' => 'person-two', 'name' => 'Maria Santos', 'role' => null],
                    ['id' => 'person-one', 'name' => 'Juan Dela Cruz'],
                ]],
                ['id' => 'group-friends', 'name' => 'Friends', 'people' => []],
            ],
        ];

        $this->actingAs($owner)->putJson("/api/events/{$event->id}/website/sections/{$people->id}", ['content' => $content])
            ->assertOk();

        $this->assertSame($content, $people->refresh()->content);
        $this->assertSame(['group-sponsors', 'group-friends'], collect($people->content['groups'])->pluck('id')->all());
        $this->assertSame(['person-two', 'person-one'], collect($people->content['groups'][0]['people'])->pluck('id')->all());
    }

    public function test_people_validation_rejects_duplicate_ids_missing_names_and_limits(): void
    {
        [$event, $owner] = $this->eventWithWebsite();
        $people = $event->website->sections()->where('type', 'people')->sole();
        $url = "/api/events/{$event->id}/website/sections/{$people->id}";

        $this->actingAs($owner)->putJson($url, ['content' => ['heading' => '', 'groups' => [
            ['id' => 'same', 'name' => 'One', 'people' => []],
            ['id' => 'same', 'name' => 'Two', 'people' => []],
        ]]])->assertUnprocessable()->assertJsonValidationErrors('content.groups.1.id');

        $this->actingAs($owner)->putJson($url, ['content' => ['heading' => '', 'groups' => [
            ['id' => 'one', 'name' => 'One', 'people' => [['id' => 'person', 'name' => 'A']]],
            ['id' => 'two', 'name' => 'Two', 'people' => [['id' => 'person', 'name' => 'B']]],
        ]]])->assertUnprocessable()->assertJsonValidationErrors('content.groups.1.people.0.id');

        $this->actingAs($owner)->putJson($url, ['content' => ['heading' => '', 'groups' => [
            ['id' => 'one', 'name' => '', 'people' => [['id' => 'person', 'name' => '']]],
        ]]])->assertUnprocessable()->assertJsonValidationErrors(['content.groups.0.name', 'content.groups.0.people.0.name']);

        $groups = array_map(fn (int $index): array => ['id' => "group-{$index}", 'name' => 'Group', 'people' => []], range(1, 31));
        $this->actingAs($owner)->putJson($url, ['content' => ['heading' => '', 'groups' => $groups]])
            ->assertUnprocessable()->assertJsonValidationErrors('content.groups');

        $group = ['id' => 'large', 'name' => 'Large', 'people' => array_map(
            fn (int $index): array => ['id' => "person-{$index}", 'name' => 'Person'],
            range(1, 101),
        )];
        $this->actingAs($owner)->putJson($url, ['content' => ['heading' => '', 'groups' => [$group]]])
            ->assertUnprocessable()->assertJsonValidationErrors('content.groups.0.people');
    }

    public function test_visibility_changes_preserve_people_content(): void
    {
        [$event, $owner] = $this->eventWithWebsite();
        $people = $event->website->sections()->where('type', 'people')->sole();
        $content = ['heading' => 'Wedding Party', 'groups' => [[
            'id' => 'group', 'name' => 'Best Friends', 'people' => [['id' => 'person', 'name' => 'Alex', 'role' => 'Best Person']],
        ]]];
        $people->update(['content' => $content]);

        $enabledUrl = "/api/events/{$event->id}/website/sections/{$people->id}/enabled";
        $this->actingAs($owner)->putJson($enabledUrl, ['isEnabled' => false])->assertOk();
        $this->assertSame($content, $people->refresh()->content);
        $this->actingAs($owner)->putJson($enabledUrl, ['isEnabled' => true])->assertOk();
        $this->assertSame($content, $people->refresh()->content);
    }

    /** @return array{Event, User} */
    private function eventWithWebsite(): array
    {
        $owner = User::factory()->create();
        $event = app(CreateEvent::class)->handle($owner, ['name' => fake()->words(3, true)]);
        $this->initializeWebsite($event);

        return [$event->refresh(), $owner];
    }
}
