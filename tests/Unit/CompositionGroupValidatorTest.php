<?php

namespace Tests\Unit;

use App\Website\Elements\CompositionGroupValidator;
use App\Website\Elements\WebsiteElementValidator;
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

    public function test_group_accepts_layout_children_and_one_nested_group(): void
    {
        $group = ['id' => 'outer', 'type' => 'compositionGroup', 'children' => [
            ['id' => 'text', 'type' => 'text', 'text' => 'Hello'],
            ['id' => 'inner', 'type' => 'compositionGroup', 'children' => [['id' => 'rich', 'type' => 'richText', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]]]], 'layout' => ['direction' => 'vertical']],
        ], 'layout' => ['width' => 'narrow', 'direction' => 'horizontal', 'gap' => 'l', 'padding' => ['top' => 's', 'right' => 'm', 'bottom' => 's', 'left' => 'm'], 'alignment' => 'center', 'columns' => 'equal-2', 'responsive' => ['mobile' => ['direction' => 'vertical', 'gap' => 's']]]];
        $this->assertSame($group, $this->validator->validate($group));
    }

    public function test_group_accepts_canonical_rich_text_with_multiple_runs_marks_and_lists(): void
    {
        $richText = ['id' => 'rich', 'type' => 'richText', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [['text' => 'Lorem '], ['text' => 'ipsum', 'marks' => ['bold' => true]]]],
            ['type' => 'bulletList', 'items' => [[['text' => 'One', 'marks' => ['italic' => true]]], [['text' => 'Two']]]],
        ]]];
        $group = ['id' => 'group', 'type' => 'compositionGroup', 'children' => [$richText], 'layout' => ['direction' => 'vertical']];

        $this->assertSame($group, $this->validator->validate($group));
    }

    public function test_group_normalizes_editor_metadata_from_nested_rich_text_runs(): void
    {
        $group = ['id' => 'group', 'type' => 'compositionGroup', 'children' => [['id' => 'rich', 'type' => 'richText', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [['text' => 'Copy', 'editorMetadata' => true]]],
        ]]]], 'layout' => []];

        $validated = $this->validator->validate($group);

        $this->assertSame(['text' => 'Copy'], $validated['children'][0]['document']['children'][0]['children'][0]);
    }

    public function test_group_rejects_old_placeholder_shape_unknown_layout_and_third_level(): void
    {
        foreach ([
            ['id' => 'old', 'type' => 'compositionGroup', 'composition' => 'flow', 'children' => []],
            ['id' => 'bad', 'type' => 'compositionGroup', 'children' => [], 'layout' => ['gap' => 'huge']],
            ['id' => 'one', 'type' => 'compositionGroup', 'children' => [['id' => 'two', 'type' => 'compositionGroup', 'children' => [['id' => 'three', 'type' => 'compositionGroup', 'children' => []]]]]],
        ] as $element) {
            try {
                $this->validator->validate($element);
                $this->fail('Expected validation failure.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_group_tree_ids_are_unique(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(['id' => 'group', 'type' => 'compositionGroup', 'children' => [['id' => 'same', 'type' => 'text', 'text' => 'A'], ['id' => 'same', 'type' => 'text', 'text' => 'B']]]);
    }
}
