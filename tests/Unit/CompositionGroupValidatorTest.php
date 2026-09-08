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
        $group = ['id' => 'outer', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [
            ['id' => 'text', 'type' => 'text', 'editorName' => 'Text 1', 'text' => 'Hello'],
            ['id' => 'inner', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'rich', 'type' => 'richText', 'editorName' => 'Rich Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]]]], 'layout' => ['direction' => 'vertical']],
        ], 'layout' => ['width' => 'narrow', 'direction' => 'horizontal', 'gap' => 'l', 'padding' => ['top' => 's', 'right' => 'm', 'bottom' => 's', 'left' => 'm'], 'alignment' => 'center', 'columns' => 'equal-2', 'responsive' => ['tablet' => ['width' => 'medium'], 'mobile' => ['width' => 'full', 'direction' => 'vertical', 'gap' => 's']]]];
        $this->assertSame($group, $this->validator->validate($group));
    }

    public function test_group_accepts_canonical_rich_text_with_multiple_paragraphs_and_marks(): void
    {
        $richText = ['id' => 'rich', 'type' => 'richText', 'editorName' => 'Rich Text 1', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [['text' => 'Lorem '], ['text' => 'ipsum', 'marks' => ['bold' => true]]]],
            ['type' => 'paragraph', 'children' => [['text' => 'One', 'marks' => ['italic' => true]]]],
            ['type' => 'paragraph', 'children' => [['text' => 'Two']]],
        ]]];
        $group = ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [$richText], 'layout' => ['direction' => 'vertical']];

        $this->assertSame($group, $this->validator->validate($group));
    }

    public function test_group_accepts_background_appearance(): void
    {
        $group = ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [], 'appearance' => ['backgroundColorId' => 'sage-accent', 'shadow' => 'medium', 'decorativeAppearance' => ['background' => ['texture' => 'paper', 'textureStrength' => 40, 'pattern' => 'botanical', 'patternStrength' => 60]]]];

        $this->assertSame($group, $this->validator->validate($group));
    }

    public function test_group_and_nested_generic_children_preserve_hidden_state(): void
    {
        $group = ['id' => 'outer', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'isHidden' => true, 'children' => [
            ['id' => 'text', 'type' => 'text', 'editorName' => 'Text 1', 'text' => 'Hidden child', 'isHidden' => true],
            ['id' => 'inner', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'isHidden' => true, 'children' => []],
        ]];

        $this->assertSame($group, $this->validator->validate($group));
    }

    public function test_group_rejects_editor_metadata_from_nested_rich_text_runs(): void
    {
        $group = ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'rich', 'type' => 'richText', 'editorName' => 'Rich Text 1', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [['text' => 'Copy', 'editorMetadata' => true]]],
        ]]]], 'layout' => []];

        $this->expectException(ValidationException::class);
        $this->validator->validate($group);
    }

    public function test_group_rejects_old_placeholder_shape_unknown_layout_and_third_level(): void
    {
        foreach ([
            ['id' => 'old', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'composition' => 'flow', 'children' => []],
            ['id' => 'bad', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [], 'layout' => ['gap' => 'huge']],
            ['id' => 'unsupported', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'heading', 'type' => 'heading', 'text' => 'No']]],
            ['id' => 'one', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'two', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'three', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => []]]]]],
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
        $this->validator->validate(['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [['id' => 'same', 'type' => 'text', 'editorName' => 'Text 1', 'text' => 'A'], ['id' => 'same', 'type' => 'text', 'editorName' => 'Text 1', 'text' => 'B']]]);
    }
}
