<?php

namespace Tests\Unit;

use App\Website\WebsiteSectionContentValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SectionChildFlowValidatorTest extends TestCase
{
    public function test_date_accepts_valid_optional_text_child_flow(): void
    {
        $validator = app(WebsiteSectionContentValidator::class);
        $this->assertSame($this->content(), $validator->validate('date', $this->content(), ['text']));
        $this->assertSame(['heading' => 'When', 'description' => 'Noon'], $validator->validate('date', ['heading' => 'When', 'description' => 'Noon']));
    }

    public function test_removed_dress_code_section_is_not_editable(): void
    {
        $this->expectException(ValidationException::class);
        app(WebsiteSectionContentValidator::class)->validate('dressCode', ['heading' => 'Attire', 'description' => 'Formal']);
    }

    #[DataProvider('invalidFlowProvider')]
    public function test_child_flow_invariants_are_strict(array $flow): void
    {
        $this->expectException(ValidationException::class);
        app(WebsiteSectionContentValidator::class)->validate('date', ['heading' => 'When', 'description' => 'Noon', 'childFlow' => $flow], ['text']);
    }

    public static function invalidFlowProvider(): array
    {
        $specialized = ['kind' => 'specialized', 'key' => 'content'];
        $element = ['id' => 'a', 'type' => 'text', 'editorName' => 'Text 1', 'text' => 'Hello', 'appearance' => []];

        return [
            'missing specialized' => [['elements' => [$element], 'order' => [['kind' => 'element', 'id' => 'a']]]],
            'duplicate specialized' => [['elements' => [$element], 'order' => [$specialized, $specialized, ['kind' => 'element', 'id' => 'a']]]],
            'missing reference' => [['elements' => [$element], 'order' => [$specialized]]],
            'duplicate reference' => [['elements' => [$element], 'order' => [$specialized, ['kind' => 'element', 'id' => 'a'], ['kind' => 'element', 'id' => 'a']]]],
            'unknown reference' => [['elements' => [$element], 'order' => [$specialized, ['kind' => 'element', 'id' => 'missing']]]],
            'duplicate IDs' => [['elements' => [$element, $element], 'order' => [$specialized, ['kind' => 'element', 'id' => 'a'], ['kind' => 'element', 'id' => 'a']]]],
            'invalid Text' => [['elements' => [[...$element, 'appearance' => ['fontWeight' => 500]]], 'order' => [$specialized, ['kind' => 'element', 'id' => 'a']]]],
            'disallowed element' => [['elements' => [['id' => 'a', 'type' => 'divider', 'editorName' => 'Divider 1']], 'order' => [$specialized, ['kind' => 'element', 'id' => 'a']]]],
            'unknown discriminator' => [['elements' => [['id' => 'a', 'type' => 'unknown']], 'order' => [$specialized, ['kind' => 'element', 'id' => 'a']]]],
            'unknown flow key' => [['elements' => [$element], 'order' => [$specialized, ['kind' => 'element', 'id' => 'a']], 'extra' => true]],
            'unknown reference key' => [['elements' => [$element], 'order' => [$specialized, ['kind' => 'element', 'id' => 'a', 'extra' => true]]]],
        ];
    }

    public function test_closed_sections_reject_child_flow(): void
    {
        $this->expectException(ValidationException::class);
        app(WebsiteSectionContentValidator::class)->validate('hero', ['headline' => 'Hello', 'subheadline' => '', 'childFlow' => $this->content()['childFlow']]);
    }

    public function test_blank_accepts_empty_and_ordered_generic_only_flows(): void
    {
        $validator = app(WebsiteSectionContentValidator::class);
        $empty = ['childFlow' => ['elements' => [], 'order' => []]];
        $this->assertSame($empty, $validator->validate('blank', $empty, ['text']));

        $element = ['id' => 'a', 'type' => 'text', 'editorName' => 'Text 1', 'text' => 'Hello'];
        $ordered = ['childFlow' => ['elements' => [$element], 'order' => [['kind' => 'element', 'id' => 'a']]]];
        $this->assertSame($ordered, $validator->validate('blank', $ordered, ['text']));
    }

    public function test_date_block_is_valid_at_blank_root_and_nested_group_without_persisted_event_data(): void
    {
        $date = ['id' => 'date-1', 'type' => 'date', 'editorName' => 'Ceremony date', 'isHidden' => true];
        $nestedDate = ['id' => 'date-2', 'type' => 'date', 'editorName' => 'Nested date'];
        $group = ['id' => 'group-1', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [$nestedDate]];
        $content = ['childFlow' => ['elements' => [$date, $group], 'order' => [
            ['kind' => 'element', 'id' => 'date-1'],
            ['kind' => 'element', 'id' => 'group-1'],
        ]]];

        $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate(
            'blank',
            $content,
            ['date', 'compositionGroup'],
        ));
    }

    public function test_blank_rejects_specialized_and_malformed_references(): void
    {
        $validator = app(WebsiteSectionContentValidator::class);
        foreach ([
            ['elements' => [], 'order' => [['kind' => 'specialized', 'key' => 'content']]],
            ['elements' => [['id' => 'a', 'type' => 'text', 'editorName' => 'Text 1', 'text' => 'Hello']], 'order' => [['kind' => 'element', 'id' => 'missing']]],
        ] as $flow) {
            try {
                $validator->validate('blank', ['childFlow' => $flow], ['text']);
                $this->fail('Invalid Blank flow was accepted.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function content(): array
    {
        return [
            'heading' => 'When',
            'description' => 'Noon',
            'childFlow' => [
                'elements' => [['id' => 'a', 'type' => 'text', 'editorName' => 'Text 1', 'text' => 'Before', 'appearance' => []]],
                'order' => [['kind' => 'element', 'id' => 'a'], ['kind' => 'specialized', 'key' => 'content']],
            ],
        ];
    }
}
