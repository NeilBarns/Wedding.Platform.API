<?php

namespace Tests\Unit;

use App\Website\Elements\CompositionGroupValidator;
use App\Website\Elements\WebsiteElementValidator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompositionGroupValidatorTest extends TestCase
{
    private WebsiteElementValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new WebsiteElementValidator(new CompositionGroupValidator);
    }

    public function test_flow_and_zoned_groups_accept_conservative_leaf_categories(): void
    {
        $flow = ['id' => 'flow', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => [
            ['id' => 'heading', 'type' => 'heading', 'text' => 'Welcome'],
            ['id' => 'image', 'type' => 'image', 'mediaId' => (string) Str::ulid()],
        ]];
        $zoned = ['id' => 'zoned', 'type' => 'compositionGroup', 'composition' => 'zoned', 'zones' => [
            'media' => [['id' => 'media', 'type' => 'image', 'mediaId' => (string) Str::ulid()]],
            'content' => [
                ['id' => 'title', 'type' => 'heading', 'text' => 'Heading'],
                ['id' => 'rule', 'type' => 'divider'],
                ['id' => 'date', 'type' => 'eventDate'],
            ],
        ]];

        $this->assertSame($flow, $this->validator->validate($flow));
        $this->assertSame($zoned, $this->validator->validate($zoned));
    }

    public function test_group_collections_are_required_but_may_be_empty(): void
    {
        $emptyFlow = ['id' => 'flow', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => []];
        $emptyZoned = ['id' => 'zoned', 'type' => 'compositionGroup', 'composition' => 'zoned', 'zones' => [
            'media' => [],
            'content' => [],
        ]];

        $this->assertSame($emptyFlow, $this->validator->validate($emptyFlow));
        $this->assertSame($emptyZoned, $this->validator->validate($emptyZoned));
    }

    public function test_groups_reject_unknown_keys_zones_and_nested_groups(): void
    {
        $this->assertInvalid(['id' => 'group', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => [], 'layout' => 'grid']);
        $this->assertInvalid(['id' => 'group', 'type' => 'compositionGroup', 'composition' => 'zoned', 'zones' => ['media' => [], 'content' => [], 'aside' => []]]);
        $this->assertInvalid(['id' => 'outer', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => [
            ['id' => 'inner', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => []],
        ]]);
    }

    public function test_group_requires_its_discriminator_and_a_bounded_nonblank_id(): void
    {
        $valid = ['id' => 'group', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => []];
        $missingType = $valid;
        unset($missingType['type']);

        $this->assertInvalid($missingType);
        $this->assertInvalid([...$valid, 'type' => 'unknown']);
        $this->assertInvalid([...$valid, 'id' => '   ']);
        $this->assertInvalid([...$valid, 'id' => str_repeat('x', 256)]);
    }

    public function test_zones_reject_semantically_invalid_leaf_types(): void
    {
        $this->assertInvalid(['id' => 'group', 'type' => 'compositionGroup', 'composition' => 'zoned', 'zones' => [
            'media' => [['id' => 'text', 'type' => 'text', 'text' => 'No']],
            'content' => [],
        ]]);
        $this->assertInvalid(['id' => 'group', 'type' => 'compositionGroup', 'composition' => 'zoned', 'zones' => [
            'media' => [],
            'content' => [['id' => 'image', 'type' => 'image', 'mediaId' => (string) Str::ulid()]],
        ]]);
    }

    public function test_each_group_allows_at_most_one_media_collection(): void
    {
        $collection = fn (string $id): array => ['id' => $id, 'type' => 'mediaCollection', 'items' => [
            ['id' => "{$id}-item", 'mediaId' => (string) Str::ulid()],
        ]];
        $this->assertInvalid(['id' => 'group', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => [
            $collection('one'), $collection('two'),
        ]]);
        $this->assertInvalid(['id' => 'group', 'type' => 'compositionGroup', 'composition' => 'zoned', 'zones' => [
            'media' => [$collection('one'), $collection('two')],
            'content' => [],
        ]]);
    }

    public function test_ids_are_unique_across_the_complete_supplied_section_tree(): void
    {
        $this->assertTreeInvalid([
            ['id' => 'duplicate', 'type' => 'text', 'text' => 'Outside'],
            ['id' => 'group', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => [
                ['id' => 'duplicate', 'type' => 'divider'],
            ]],
        ]);
        $this->assertTreeInvalid([
            ['id' => 'group', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => [
                ['id' => 'group', 'type' => 'divider'],
            ]],
        ]);
        $this->assertTreeInvalid([
            ['id' => 'first-group', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => [
                ['id' => 'shared-child', 'type' => 'divider'],
            ]],
            ['id' => 'second-group', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => [
                ['id' => 'shared-child', 'type' => 'text', 'text' => 'Duplicate'],
            ]],
        ]);
    }

    public function test_direct_group_validation_enforces_subtree_uniqueness(): void
    {
        $this->assertInvalid(['id' => 'flow', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => [
            ['id' => 'duplicate', 'type' => 'divider'],
            ['id' => 'duplicate', 'type' => 'text', 'text' => 'Duplicate'],
        ]]);
        $this->assertInvalid(['id' => 'zoned', 'type' => 'compositionGroup', 'composition' => 'zoned', 'zones' => [
            'media' => [['id' => 'duplicate', 'type' => 'image', 'mediaId' => (string) Str::ulid()]],
            'content' => [['id' => 'duplicate', 'type' => 'heading', 'text' => 'Duplicate']],
        ]]);
        $this->assertInvalid(['id' => 'duplicate', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => [
            ['id' => 'collection', 'type' => 'mediaCollection', 'items' => [
                ['id' => 'duplicate', 'mediaId' => (string) Str::ulid()],
            ]],
        ]]);
    }

    private function assertInvalid(array $element): void
    {
        try {
            $this->validator->validate($element);
            $this->fail('Expected the Composition Group to be invalid.');
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
