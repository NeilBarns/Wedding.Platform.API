<?php

namespace Tests\Unit;

use App\Website\WebsiteSectionContentValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SectionChildFlowValidatorTest extends TestCase
{
    public function test_date_and_dress_code_accept_valid_optional_text_child_flow(): void
    {
        $validator = app(WebsiteSectionContentValidator::class);
        $this->assertSame($this->content(), $validator->validate('date', $this->content(), ['text']));
        $this->assertSame($this->content(), $validator->validate('dressCode', $this->content(), ['text']));
        $this->assertSame(['heading' => 'When', 'description' => 'Noon'], $validator->validate('date', ['heading' => 'When', 'description' => 'Noon']));
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
        $element = ['id' => 'a', 'type' => 'text', 'text' => 'Hello', 'appearance' => []];

        return [
            'missing specialized' => [['elements' => [$element], 'order' => [['kind' => 'element', 'id' => 'a']]]],
            'duplicate specialized' => [['elements' => [$element], 'order' => [$specialized, $specialized, ['kind' => 'element', 'id' => 'a']]]],
            'missing reference' => [['elements' => [$element], 'order' => [$specialized]]],
            'duplicate reference' => [['elements' => [$element], 'order' => [$specialized, ['kind' => 'element', 'id' => 'a'], ['kind' => 'element', 'id' => 'a']]]],
            'unknown reference' => [['elements' => [$element], 'order' => [$specialized, ['kind' => 'element', 'id' => 'missing']]]],
            'duplicate IDs' => [['elements' => [$element, $element], 'order' => [$specialized, ['kind' => 'element', 'id' => 'a'], ['kind' => 'element', 'id' => 'a']]]],
            'invalid Text' => [['elements' => [[...$element, 'appearance' => ['fontWeight' => 500]]], 'order' => [$specialized, ['kind' => 'element', 'id' => 'a']]]],
            'disallowed element' => [['elements' => [['id' => 'a', 'type' => 'divider']], 'order' => [$specialized, ['kind' => 'element', 'id' => 'a']]]],
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

    private function content(): array
    {
        return [
            'heading' => 'When',
            'description' => 'Noon',
            'childFlow' => [
                'elements' => [['id' => 'a', 'type' => 'text', 'text' => 'Before', 'appearance' => []]],
                'order' => [['kind' => 'element', 'id' => 'a'], ['kind' => 'specialized', 'key' => 'content']],
            ],
        ];
    }
}
