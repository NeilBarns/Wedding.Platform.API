<?php

namespace Tests\Unit;

use App\Website\Elements\NarrativeBlockValidator;
use App\Website\StoryContentNormalizer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NarrativeBlockTest extends TestCase
{
    public function test_legacy_narrative_normalizes_without_losing_hidden_or_empty_semantics(): void
    {
        $legacy = ['id' => 'chapter', 'type' => 'narrativeBlock', 'heading' => 'How We Met', 'body' => '', 'media' => ['type' => 'image', 'mediaId' => '01M0Q08NQ9XJB9A7B5SGC45YD9']];
        $canonical = app(StoryContentNormalizer::class)->normalizeLegacyNarrativeBlock($legacy);

        $this->assertFalse($canonical['isHidden']);
        $this->assertFalse($canonical['slots']['heading']['isHidden']);
        $this->assertSame('How We Met', $canonical['slots']['heading']['text']);
        $this->assertFalse($canonical['slots']['body']['isHidden']);
        $this->assertSame('', $canonical['slots']['body']['text']);
        $this->assertTrue($canonical['slots']['divider']['isHidden']);
        $this->assertSame($legacy['media'], $canonical['slots']['media']['content']);
        $current = [...$canonical, 'composition' => ['presentation' => 'editorial']];
        $this->assertSame($current, app(NarrativeBlockValidator::class)->validate($current));
    }

    public function test_hidden_content_and_sparse_appearance_are_preserved(): void
    {
        $block = $this->canonical();
        $block['isHidden'] = true;
        $block['slots']['heading'] = ['isHidden' => true, 'text' => 'Keep me', 'appearance' => ['fontSize' => ['mobile' => 'l']]];
        $block['slots']['body'] = ['isHidden' => true, 'text' => 'Keep body'];
        $block['slots']['cta']['appearance'] = ['colorId' => 'accent', 'letterSpacing' => 'wide'];

        $this->assertSame($block, app(NarrativeBlockValidator::class)->validate($block));
    }

    public function test_media_corner_appearance_is_sparse_validated_and_preserved(): void
    {
        foreach (['square', 'soft', 'rounded'] as $cornerStyle) {
            $block = $this->canonical();
            $block['slots']['media']['appearance'] = ['cornerStyle' => $cornerStyle];
            $this->assertSame($block, app(NarrativeBlockValidator::class)->validate($block));
            $this->assertSame($block, app(StoryContentNormalizer::class)->normalizeLegacyNarrativeBlock($block));
        }

        $sparse = $this->canonical();
        $this->assertArrayNotHasKey('appearance', $sparse['slots']['media']);
        $this->assertSame($sparse, app(NarrativeBlockValidator::class)->validate($sparse));
    }

    public function test_media_frame_appearance_accepts_none_and_semantic_ids_and_preserves_corners(): void
    {
        foreach (['none', 'ornamentalCorners'] as $frameStyle) {
            $block = $this->canonical();
            $block['slots']['media']['appearance'] = ['cornerStyle' => 'soft', 'frameStyle' => $frameStyle];
            $this->assertSame($block, app(NarrativeBlockValidator::class)->validate($block));
            $this->assertSame($block, app(StoryContentNormalizer::class)->normalizeLegacyNarrativeBlock($block));
        }
    }

    public function test_media_frame_color_and_size_are_sparse_validated_against_website_colors(): void
    {
        foreach (['small', 'medium', 'large'] as $frameSize) {
            $block = $this->canonical();
            $block['slots']['media']['appearance'] = [
                'frameStyle' => 'ornamentalCorners',
                'frameColorId' => 'accent',
                'frameSize' => $frameSize,
            ];
            $this->assertSame($block, app(NarrativeBlockValidator::class)->validate($block, [], ['frame' => ['accent']]));
        }

        $projectColor = 'project-color-01KED9H9XR7WQBP4JTKP1YYQ3G';
        $block = $this->canonical();
        $block['slots']['media']['appearance'] = ['frameColorId' => $projectColor];
        $this->assertSame($block, app(NarrativeBlockValidator::class)->validate($block, [], ['frame' => [$projectColor]]));

        $this->expectException(ValidationException::class);
        app(NarrativeBlockValidator::class)->validate($block, [], ['frame' => []]);
    }

    #[DataProvider('invalidProvider')]
    public function test_current_contract_rejects_unknown_recursive_and_untyped_values(callable $mutate): void
    {
        $this->expectException(ValidationException::class);
        app(NarrativeBlockValidator::class)->validate($mutate($this->canonical()));
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
            'invalid media corner' => [fn (array $block): array => tap($block, fn (&$value) => $value['slots']['media']['appearance'] = ['cornerStyle' => 'pill'])],
            'invalid media frame type' => [fn (array $block): array => tap($block, fn (&$value) => $value['slots']['media']['appearance'] = ['frameStyle' => 12])],
            'invalid media frame identifier' => [fn (array $block): array => tap($block, fn (&$value) => $value['slots']['media']['appearance'] = ['frameStyle' => '../frame'])],
            'invalid media frame size' => [fn (array $block): array => tap($block, fn (&$value) => $value['slots']['media']['appearance'] = ['frameSize' => 'extraLarge'])],
            'invalid media frame color type' => [fn (array $block): array => tap($block, fn (&$value) => $value['slots']['media']['appearance'] = ['frameColorId' => ['raw' => '#fff']])],
            'unknown media appearance' => [fn (array $block): array => tap($block, fn (&$value) => $value['slots']['media']['appearance'] = ['radius' => '12px'])],
        ];
    }

    private function canonical(): array
    {
        return app(StoryContentNormalizer::class)->normalizeToCurrent('story', ['heading' => '', 'intro' => null, 'elements' => [['id' => 'chapter', 'type' => 'narrativeBlock', 'body' => 'Body']], 'mediaFraming' => []])['elements'][0];
    }
}
