<?php

namespace Tests\Unit;

use App\Website\Elements\CompositionGroupValidator;
use App\Website\Elements\WebsiteElementValidator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TextElementValidatorTest extends TestCase
{
    private WebsiteElementValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new WebsiteElementValidator(new CompositionGroupValidator);
    }

    public function test_minimal_canonical_text_normalizes_line_breaks_without_writing_defaults(): void
    {
        $this->assertSame([
            'id' => 'text-1', 'type' => 'text', 'text' => 'one two three',
        ], $this->validator->validate(['id' => 'text-1', 'type' => 'text', 'text' => "one\r\n\ntwo\u{2029}three"]));
    }

    public function test_complete_text_contract_is_accepted(): void
    {
        $element = $this->base();
        $element['appearance'] = [
            'fontFamilyId' => 'inter', 'fontSize' => 'l', 'fontWeight' => 700,
            'lineHeight' => 'tight', 'letterSpacing' => 'wide', 'alignment' => 'center',
            'colorId' => 'ink-text', 'italic' => true, 'underline' => true,
            'strikethrough' => false, 'textTransform' => 'uppercase',
            'responsive' => ['tablet' => ['fontSize' => 'm'], 'mobile' => ['alignment' => 'end']],
        ];
        $this->assertSame($element, $this->validator->validate($element));
    }

    #[DataProvider('invalidTextProvider')]
    public function test_text_contract_is_strict(array $mutation): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate(array_replace_recursive($this->base(), $mutation));
    }

    public static function invalidTextProvider(): array
    {
        return [
            'obsolete semantic tag' => [['semanticTag' => 'h1']],
            'layout property' => [['appearance' => ['margin' => '1rem']]],
            'desktop branch' => [['appearance' => ['responsive' => ['desktop' => ['fontSize' => 'l']]]]],
            'responsive global property' => [['appearance' => ['responsive' => ['mobile' => ['italic' => true]]]]],
            'unsupported weight' => [['appearance' => ['fontFamilyId' => 'dm-serif-display', 'fontWeight' => 700]]],
            'unsupported italic' => [['appearance' => ['fontFamilyId' => 'quicksand', 'italic' => true]]],
            'unknown family' => [['appearance' => ['fontFamilyId' => 'unknown']]],
            'unbounded weight' => [['appearance' => ['fontWeight' => 500]]],
            'unknown top-level key' => [['role' => 'heading']],
        ];
    }

    private function base(): array
    {
        return ['id' => 'text-1', 'type' => 'text', 'text' => 'Hello', 'appearance' => []];
    }
}
