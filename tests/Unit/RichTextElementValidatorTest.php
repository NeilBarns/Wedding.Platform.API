<?php

namespace Tests\Unit;

use App\Website\Elements\CompositionGroupValidator;
use App\Website\Elements\WebsiteElementValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RichTextElementValidatorTest extends TestCase
{
    public function test_structured_paragraph_rich_text_with_supported_marks_is_accepted(): void
    {
        $element = [
            'id' => 'rich-1', 'type' => 'richText', 'editorName' => 'Rich Text 1',
            'document' => ['type' => 'doc', 'children' => [
                ['type' => 'paragraph', 'children' => [['text' => 'Hello ', 'marks' => ['bold' => true]], ['text' => 'world', 'marks' => ['italic' => true, 'underline' => true, 'strikethrough' => true]]]],
            ]],
            'appearance' => ['fontFamilyId' => 'inter', 'fontSize' => 'm', 'fontWeight' => 700, 'lineHeight' => 'relaxed', 'letterSpacing' => 'wide', 'alignment' => 'center', 'colorId' => 'ink', 'responsive' => ['tablet' => ['fontSize' => 'l', 'alignment' => 'start'], 'mobile' => ['fontSize' => 's', 'alignment' => 'end']]],
        ];

        $this->assertSame($element, (new WebsiteElementValidator(new CompositionGroupValidator))->validate($element));
    }

    public function test_rejects_link_marks(): void
    {
        $this->expectException(ValidationException::class);
        (new WebsiteElementValidator(new CompositionGroupValidator))->validate(['id' => 'rich-1', 'type' => 'richText', 'editorName' => 'Rich Text 1', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [['text' => 'Bad', 'marks' => ['link' => 'https://example.com']]]],
        ]]]);
    }

    #[DataProvider('invalidRichTextStructureProvider')]
    public function test_rejects_unknown_fields_and_noncanonical_document_structure(array $element): void
    {
        $this->expectException(ValidationException::class);
        (new WebsiteElementValidator(new CompositionGroupValidator))->validate($element);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRichTextStructureProvider(): iterable
    {
        $base = ['id' => 'rich-1', 'type' => 'richText', 'editorName' => 'Rich Text 1', 'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]]];
        yield 'unknown top-level field' => [$base + ['editorMetadata' => true]];
        $unknownBlock = $base;
        $unknownBlock['document'] = ['type' => 'doc', 'children' => [['type' => 'heading', 'children' => [['text' => 'No']]]]];
        yield 'unknown document block' => [$unknownBlock];
        $list = $base;
        $list['document'] = [
            'type' => 'doc',
            'children' => [[
                'type' => 'bulletList',
                'items' => [[['text' => 'No']]],
            ]],
        ];
        yield 'list blocks are noncanonical' => [$list];
        $link = $base;
        $link['document'] = [
            'type' => 'doc',
            'children' => [[
                'type' => 'paragraph',
                'children' => [['text' => 'No', 'marks' => ['link' => 'https://example.com']]],
            ]],
        ];
        yield 'link mark is noncanonical' => [$link];
    }

    #[DataProvider('invalidRichTextAppearanceProvider')]
    public function test_rejects_noncanonical_or_unsupported_rich_text_appearance(array $appearance): void
    {
        $this->expectException(ValidationException::class);
        (new WebsiteElementValidator(new CompositionGroupValidator))->validate([
            'id' => 'rich-1', 'type' => 'richText', 'editorName' => 'Rich Text 1',
            'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [['text' => 'Copy']]]]],
            'appearance' => $appearance,
        ]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRichTextAppearanceProvider(): iterable
    {
        yield 'removed text transform' => [['textTransform' => 'uppercase']];
        yield 'unsupported global font weight' => [['fontFamilyId' => 'great-vibes', 'fontWeight' => 700]];
        yield 'unknown appearance field' => [['unknown' => true]];
        yield 'responsive font weight' => [['responsive' => ['mobile' => ['fontWeight' => 700]]]];
        yield 'malformed font weight' => [['fontWeight' => 500]];
    }

    public function test_canonical_runs_and_all_supported_marks_are_preserved(): void
    {
        $element = ['id' => 'rich-1', 'type' => 'richText', 'editorName' => 'Rich Text 1', 'document' => ['type' => 'doc', 'children' => [
            ['type' => 'paragraph', 'children' => [[
                'text' => 'Formatted',
                'marks' => ['bold' => true, 'italic' => true, 'underline' => true, 'strikethrough' => true],
            ]]],
        ]]];

        $this->assertSame($element, (new WebsiteElementValidator(new CompositionGroupValidator))->validate($element));
    }

    #[DataProvider('invalidCanonicalRunProvider')]
    public function test_rejects_noncanonical_runs_and_marks(mixed $run): void
    {
        $this->expectException(ValidationException::class);
        (new WebsiteElementValidator(new CompositionGroupValidator))->validate([
            'id' => 'rich-1', 'type' => 'richText', 'editorName' => 'Rich Text 1',
            'document' => ['type' => 'doc', 'children' => [['type' => 'paragraph', 'children' => [$run]]]],
        ]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidCanonicalRunProvider(): iterable
    {
        yield 'unknown run key' => [['text' => 'Copy', 'editorMetadata' => true]];
        yield 'unknown mark key' => [['text' => 'Copy', 'marks' => ['bold' => true, 'editorMetadata' => true]]];
        yield 'string shorthand run' => ['Copy'];
        yield 'non-object run' => [null];
        yield 'malformed boolean mark' => [['text' => 'Copy', 'marks' => ['bold' => 'true']]];
        yield 'link mark' => [['text' => 'Copy', 'marks' => ['link' => 'https://example.com']]];
        yield 'null marks' => [['text' => 'Copy', 'marks' => null]];
        yield 'null boolean mark' => [['text' => 'Copy', 'marks' => ['bold' => null]]];
    }
}
