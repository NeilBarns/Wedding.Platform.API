<?php

namespace Tests\Unit;

use App\Website\Elements\NarrativeBlockV4Validator;
use App\Website\StoryContentNormalizer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NarrativeBlockV4Test extends TestCase
{
    public function test_legacy_narrative_normalizes_without_losing_hidden_or_empty_semantics(): void
    {
        $legacy = ['id' => 'chapter', 'type' => 'narrativeBlock', 'heading' => 'How We Met', 'body' => '', 'media' => ['type' => 'image', 'mediaId' => '01M0Q08NQ9XJB9A7B5SGC45YD9']];
        $canonical = app(StoryContentNormalizer::class)->normalizeNarrativeBlockToV4($legacy);

        $this->assertFalse($canonical['isHidden']);
        $this->assertFalse($canonical['slots']['heading']['isHidden']);
        $this->assertSame('How We Met', $canonical['slots']['heading']['text']);
        $this->assertFalse($canonical['slots']['body']['isHidden']);
        $this->assertSame('', $canonical['slots']['body']['text']);
        $this->assertTrue($canonical['slots']['divider']['isHidden']);
        $this->assertSame($legacy['media'], $canonical['slots']['media']['content']);
        $this->assertSame($canonical, app(NarrativeBlockV4Validator::class)->validate($canonical));
    }

    public function test_hidden_content_and_sparse_appearance_are_preserved(): void
    {
        $block = $this->canonical();
        $block['isHidden'] = true;
        $block['slots']['heading'] = ['isHidden' => true, 'text' => 'Keep me', 'appearance' => ['fontSize' => ['mobile' => 'l']]];
        $block['slots']['body'] = ['isHidden' => true, 'text' => 'Keep body'];
        $block['slots']['cta']['appearance'] = ['colorId' => 'accent', 'letterSpacing' => 'wide'];

        $this->assertSame($block, app(NarrativeBlockV4Validator::class)->validate($block));
    }

    #[DataProvider('invalidProvider')]
    public function test_v4_contract_rejects_unknown_recursive_and_untyped_values(callable $mutate): void
    {
        $this->expectException(ValidationException::class);
        app(NarrativeBlockV4Validator::class)->validate($mutate($this->canonical()));
    }

    public static function invalidProvider(): array
    {
        return [
            'unknown slot' => [fn (array $block): array => tap($block, fn (&$value) => $value['slots']['footnote'] = ['isHidden' => false])],
            'recursive children' => [fn (array $block): array => tap($block, fn (&$value) => $value['children'] = [])],
            'resolved appearance' => [fn (array $block): array => tap($block, fn (&$value) => $value['resolvedAppearance'] = [])],
            'raw css' => [fn (array $block): array => tap($block, fn (&$value) => $value['slots']['heading']['appearance'] = ['color' => '#fff'])],
            'unknown action' => [fn (array $block): array => tap($block, fn (&$value) => $value['slots']['cta']['action'] = ['type' => 'javascript'])],
            'invalid media' => [fn (array $block): array => tap($block, fn (&$value) => $value['slots']['media']['content'] = ['type' => 'audio', 'mediaId' => '01M0Q08NQ9XJB9A7B5SGC45YD9'])],
        ];
    }

    private function canonical(): array
    {
        return app(StoryContentNormalizer::class)->normalizeNarrativeBlockToV4(['id' => 'chapter', 'type' => 'narrativeBlock', 'body' => 'Body']);
    }
}
