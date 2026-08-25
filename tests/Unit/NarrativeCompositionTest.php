<?php

namespace Tests\Unit;

use App\Website\Elements\NarrativeBlockValidator;
use App\Website\StoryContentNormalizer;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NarrativeCompositionTest extends TestCase
{
    public function test_legacy_slot_shape_normalizes_to_current_composition_without_mutation(): void
    {
        $legacy = app(StoryContentNormalizer::class)->normalizeLegacyNarrativeBlock(['id' => 'chapter', 'type' => 'narrativeBlock', 'body' => 'Body']);
        $story = app(StoryContentNormalizer::class)->normalizeToCurrent('story', ['heading' => '', 'intro' => null, 'elements' => [$legacy], 'mediaFraming' => []]);

        $this->assertArrayNotHasKey('composition', $legacy);
        $this->assertSame(['presentation' => 'editorial'], $story['elements'][0]['composition']);
        $this->assertSame($story['elements'][0], app(NarrativeBlockValidator::class)->validate($story['elements'][0]));
    }

    public function test_sparse_composition_modifiers_are_preserved_without_compatibility_normalization(): void
    {
        $block = $this->canonical();
        $block['composition'] = [
            'presentation' => 'textOnly',
            'mediaPlacement' => 'splitEnd',
            'mediaTreatment' => 'cinematic',
            'textAlignment' => 'end',
            'surface' => 'feature',
        ];

        $this->assertSame($block, app(NarrativeBlockValidator::class)->validate($block));
    }

    #[DataProvider('invalidCompositionProvider')]
    public function test_current_contract_rejects_invalid_composition(callable $mutate): void
    {
        $this->expectException(ValidationException::class);
        app(NarrativeBlockValidator::class)->validate($mutate($this->canonical()));
    }

    public static function invalidCompositionProvider(): array
    {
        return [
            'missing composition' => [fn (array $block): array => tap($block, function (&$value): void {
                unset($value['composition']);
            })],
            'missing presentation' => [fn (array $block): array => tap($block, fn (&$value) => $value['composition'] = [])],
            'invalid presentation' => [fn (array $block): array => tap($block, fn (&$value) => $value['composition']['presentation'] = 'gallery')],
            'invalid placement' => [fn (array $block): array => tap($block, fn (&$value) => $value['composition']['mediaPlacement'] = 'absolute')],
            'invalid treatment' => [fn (array $block): array => tap($block, fn (&$value) => $value['composition']['mediaTreatment'] = 'raw')],
            'invalid alignment' => [fn (array $block): array => tap($block, fn (&$value) => $value['composition']['textAlignment'] = 'justify')],
            'invalid surface' => [fn (array $block): array => tap($block, fn (&$value) => $value['composition']['surface'] = 'inverse')],
            'unknown key' => [fn (array $block): array => tap($block, fn (&$value) => $value['composition']['padding'] = '20px')],
            'null modifier' => [fn (array $block): array => tap($block, fn (&$value) => $value['composition']['surface'] = null)],
            'resolved composition' => [fn (array $block): array => tap($block, fn (&$value) => $value['resolvedComposition'] = [])],
        ];
    }

    private function canonical(): array
    {
        return app(StoryContentNormalizer::class)->normalizeToCurrent('story', ['heading' => '', 'intro' => null, 'elements' => [['id' => 'chapter', 'type' => 'narrativeBlock', 'body' => 'Body']], 'mediaFraming' => []])['elements'][0];
    }
}
