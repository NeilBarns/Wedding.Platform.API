<?php

namespace Tests\Unit;

use App\Website\Elements\CompositionGroupValidator;
use App\Website\Elements\WebsiteElementType;
use App\Website\Elements\WebsiteElementValidator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WebsiteElementValidatorTest extends TestCase
{
    private WebsiteElementValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new WebsiteElementValidator(new CompositionGroupValidator);
    }

    #[DataProvider('validPrimitiveProvider')]
    public function test_every_active_primitive_accepts_its_canonical_shape(array $element): void
    {
        $this->assertSame($element, $this->validator->validate($element));
    }

    public static function validPrimitiveProvider(): array
    {
        $mediaId = '01J00000000000000000000000';

        return [
            'heading' => [['id' => 'heading-1', 'type' => 'heading', 'text' => 'Welcome']],
            'text' => [['id' => 'text-1', 'type' => 'text', 'text' => 'Body', 'appearance' => []]],
            'image' => [['id' => 'image-1', 'type' => 'image', 'mediaId' => $mediaId]],
            'divider' => [['id' => 'divider-1', 'type' => 'divider']],
            'quote' => [['id' => 'quote-1', 'type' => 'quote', 'text' => 'Always', 'attribution' => 'Us']],
            'cta' => [['id' => 'cta-1', 'type' => 'cta', 'label' => 'Respond', 'action' => ['type' => 'rsvp']]],
            'media collection' => [['id' => 'collection-1', 'type' => 'mediaCollection', 'items' => [['id' => 'item-1', 'mediaId' => $mediaId]]]],
            'narrative block' => [['id' => 'narrative-1', 'type' => 'narrativeBlock', 'heading' => 'Then', 'body' => 'Our story', 'media' => ['type' => 'image', 'mediaId' => $mediaId]]],
            'event date' => [['id' => 'date-1', 'type' => 'eventDate']],
            'event time' => [['id' => 'time-1', 'type' => 'eventTime']],
            'countdown' => [['id' => 'countdown-1', 'type' => 'countdown']],
        ];
    }

    public function test_active_vocabulary_is_bounded_and_does_not_accept_deferred_types(): void
    {
        $this->assertSame([
            'heading', 'text', 'richText', 'image', 'divider', 'quote', 'cta', 'mediaCollection',
            'narrativeBlock', 'compositionGroup', 'eventDate', 'eventTime', 'countdown',
        ], array_column(WebsiteElementType::cases(), 'value'));

        foreach (['video', 'locationSummary', 'logoMonogram'] as $type) {
            $this->assertInvalid(['id' => 'future-1', 'type' => $type]);
        }
    }

    #[DataProvider('primitiveProvider')]
    public function test_every_primitive_rejects_unknown_top_level_keys(array $element): void
    {
        $this->assertInvalid([...$element, 'unexpected' => true]);
    }

    #[DataProvider('primitiveProvider')]
    public function test_every_primitive_requires_a_known_discriminator(array $element): void
    {
        $missing = $element;
        unset($missing['type']);
        $this->assertInvalid($missing);
        $this->assertInvalid([...$element, 'type' => 'unknown']);
    }

    #[DataProvider('primitiveProvider')]
    public function test_every_primitive_requires_a_bounded_nonblank_id(array $element): void
    {
        $this->assertInvalid([...$element, 'id' => '   ']);
        $this->assertInvalid([...$element, 'id' => str_repeat('x', 256)]);
    }

    public static function primitiveProvider(): array
    {
        return self::validPrimitiveProvider();
    }

    public function test_ids_are_trimmed_without_imposing_ulid_or_prefix_semantics(): void
    {
        $validated = $this->validator->validate(['id' => '  arbitrary-client-id  ', 'type' => 'divider']);

        $this->assertSame('arbitrary-client-id', $validated['id']);
    }

    public function test_legacy_divider_appearance_is_migrated_to_the_locked_contract(): void
    {
        $validated = $this->validator->validate([
            'id' => 'divider',
            'type' => 'divider',
            'appearance' => ['styleId' => 'botanical-vine', 'width' => 'full', 'opacity' => 75],
        ]);

        $this->assertSame([
            'width' => 100,
            'opacity' => 75,
            'assetId' => 'botanical-vine',
        ], $validated['appearance']);
    }

    public function test_divider_accepts_only_a_normalized_integer_width_scale(): void
    {
        $base = ['id' => 'divider', 'type' => 'divider'];
        $validated = $this->validator->validate([...$base, 'appearance' => ['width' => 37]]);
        $this->assertSame(37, $validated['appearance']['width']);
        $this->assertInvalid([...$base, 'appearance' => ['width' => -1]]);
        $this->assertInvalid([...$base, 'appearance' => ['width' => 101]]);
        $this->assertInvalid([...$base, 'appearance' => ['width' => 37.5]]);
    }

    public function test_divider_accepts_continuous_integer_opacity(): void
    {
        $base = ['id' => 'divider', 'type' => 'divider'];
        $validated = $this->validator->validate([...$base, 'appearance' => ['opacity' => 63]]);
        $this->assertSame(63, $validated['appearance']['opacity']);
        $this->assertInvalid([...$base, 'appearance' => ['opacity' => 24]]);
        $this->assertInvalid([...$base, 'appearance' => ['opacity' => 101]]);
        $this->assertInvalid([...$base, 'appearance' => ['opacity' => 63.5]]);
    }

    public function test_text_limits_and_required_fields_are_enforced(): void
    {
        $this->assertInvalid(['id' => 'heading', 'type' => 'heading']);
        $this->assertInvalid(['id' => 'heading', 'type' => 'heading', 'text' => str_repeat('x', 256)]);
        $this->assertInvalid(['id' => 'text', 'type' => 'text', 'text' => str_repeat('x', 5001)]);
        $this->assertInvalid(['id' => 'quote', 'type' => 'quote', 'text' => str_repeat('x', 5001)]);
        $this->assertInvalid(['id' => 'quote', 'type' => 'quote', 'text' => 'Quote', 'attribution' => str_repeat('x', 256)]);
    }

    public function test_image_requires_a_ulid_media_reference(): void
    {
        $this->assertInvalid(['id' => 'image', 'type' => 'image', 'mediaId' => 'not-a-ulid']);
        $this->assertInvalid(['id' => 'image', 'type' => 'image', 'mediaId' => '81J00000000000000000000000']);
    }

    #[DataProvider('ctaActionProvider')]
    public function test_every_canonical_cta_action_is_accepted(array $action): void
    {
        $element = ['id' => 'cta', 'type' => 'cta', 'label' => 'Go', 'action' => $action];
        $this->assertSame($element, $this->validator->validate($element));
    }

    public static function ctaActionProvider(): array
    {
        return [
            'rsvp' => [['type' => 'rsvp']],
            'scroll' => [['type' => 'scrollToSection', 'sectionId' => 'section-1']],
            'venue' => [['type' => 'viewVenue']],
            'schedule' => [['type' => 'viewSchedule']],
            'gallery' => [['type' => 'viewGallery']],
            'top' => [['type' => 'backToTop']],
            'external' => [['type' => 'externalUrl', 'url' => 'https://example.com/path']],
        ];
    }

    public function test_cta_actions_strictly_enforce_their_payloads(): void
    {
        $base = ['id' => 'cta', 'type' => 'cta', 'label' => 'Go'];
        $this->assertInvalid([...$base, 'action' => ['type' => 'scrollToSection']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'scrollToSection', 'sectionId' => 'section', 'url' => 'https://example.com']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'externalUrl']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'externalUrl', 'url' => 'not-a-url']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'externalUrl', 'url' => 'http://example.com']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'rsvp', 'url' => 'https://example.com']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'rsvp', 'href' => '/rsvp']]);
        $this->assertInvalid([...$base, 'action' => ['type' => 'unknown']]);
    }

    public function test_media_collection_preserves_order_and_strictly_validates_items(): void
    {
        $first = (string) Str::ulid();
        $second = (string) Str::ulid();
        $element = ['id' => 'collection', 'type' => 'mediaCollection', 'items' => [
            ['id' => 'first', 'mediaId' => $first],
            ['id' => 'second', 'mediaId' => $second],
        ]];

        $this->assertSame($element, $this->validator->validate($element));
        $this->assertSame(
            ['id' => 'empty', 'type' => 'mediaCollection', 'items' => []],
            $this->validator->validate(['id' => 'empty', 'type' => 'mediaCollection', 'items' => []]),
        );
        $this->assertInvalid([...$element, 'items' => [['id' => 'first', 'mediaId' => $first, 'caption' => 'No']]]);
        $this->assertInvalid([...$element, 'items' => [['id' => 'first', 'mediaId' => 'bad']]]);
        $this->assertTreeInvalid([[...$element, 'items' => [
            ['id' => 'duplicate', 'mediaId' => $first],
            ['id' => 'duplicate', 'mediaId' => $second],
        ]]]);
    }

    public function test_narrative_block_enforces_canonical_media_and_body_contract(): void
    {
        $mediaId = (string) Str::ulid();
        $base = ['id' => 'narrative', 'type' => 'narrativeBlock', 'body' => 'Body'];

        $this->assertInvalid(['id' => 'narrative', 'type' => 'narrativeBlock']);
        $this->assertInvalid([...$base, 'body' => str_repeat('x', 10001)]);
        $this->assertInvalid([...$base, 'heading' => str_repeat('x', 256)]);
        $this->assertInvalid([...$base, 'media' => json_decode('[]', true, flags: JSON_THROW_ON_ERROR)]);
        $this->assertInvalid([...$base, 'media' => json_decode('{}', true, flags: JSON_THROW_ON_ERROR)]);
        $this->assertInvalid([...$base, 'media' => ['mediaId' => $mediaId]]);
        $this->assertInvalid([...$base, 'media' => ['type' => 'image']]);
        $this->assertInvalid([...$base, 'media' => ['type' => 'video', 'mediaId' => $mediaId]]);
        $this->assertInvalid([...$base, 'media' => ['type' => 'image', 'assetId' => $mediaId]]);
        $this->assertInvalid([...$base, 'media' => ['type' => 'image', 'mediaId' => $mediaId, 'focalPoint' => ['x' => 0.5, 'y' => 0.5]]]);
        $this->assertInvalid([...$base, 'media' => ['type' => 'image', 'mediaId' => $mediaId, 'zoom' => 2]]);
    }

    public function test_dynamic_elements_reject_copied_event_values(): void
    {
        foreach (['eventDate', 'eventTime', 'countdown'] as $type) {
            foreach (['eventDate', 'startTime', 'timeZone', 'startsAtUtc'] as $field) {
                $this->assertInvalid(['id' => $type, 'type' => $type, $field => 'copied']);
            }
        }
    }

    private function assertInvalid(array $element): void
    {
        try {
            $this->validator->validate($element);
            $this->fail('Expected the element to be invalid.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertTreeInvalid(array $elements): void
    {
        try {
            $this->validator->validateTree($elements);
            $this->fail('Expected the element tree to be invalid.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
