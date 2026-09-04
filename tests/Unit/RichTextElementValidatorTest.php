<?php

namespace Tests\Unit;

use App\Website\Elements\CompositionGroupValidator;
use App\Website\Elements\WebsiteElementValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RichTextElementValidatorTest extends TestCase
{
    public function test_structured_rich_text_with_marks_links_and_lists_is_accepted(): void
    {
        $element = ['id' => 'rich-1', 'type' => 'richText', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [['text' => 'Hello ', 'marks' => ['bold' => true]], ['text' => 'world', 'marks' => ['link' => 'https://example.com']]]],
            ['type' => 'bulletList', 'items' => [[['text' => 'First']], [['text' => 'Second', 'marks' => ['italic' => true, 'underline' => true, 'strikethrough' => true]]]]],
        ]], 'appearance' => ['fontSize' => 'm', 'alignment' => 'center', 'textTransform' => 'uppercase', 'responsive' => ['mobile' => ['fontSize' => 's']]]];

        $this->assertSame($element, (new WebsiteElementValidator(new CompositionGroupValidator))->validate($element));
    }

    public function test_rejects_arbitrary_html_and_unsafe_links(): void
    {
        $this->expectException(ValidationException::class);
        (new WebsiteElementValidator(new CompositionGroupValidator))->validate(['id' => 'rich-1', 'type' => 'richText', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [['text' => 'Bad', 'marks' => ['link' => 'javascript:alert(1)']]]],
        ]]]);
    }
}
