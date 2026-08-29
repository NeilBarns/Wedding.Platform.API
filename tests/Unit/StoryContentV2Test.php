<?php

namespace Tests\Unit;

use App\Website\StoryContentNormalizer;
use App\Website\WebsiteSectionContentValidator;
use App\Website\WebsiteSectionMediaReferences;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoryContentV2Test extends TestCase
{
    public function test_pre_block_and_blocks_generations_normalize_deterministically_to_v2(): void
    {
        $normalizer = app(StoryContentNormalizer::class);
        $mediaId = '01M0Q08NQ9XJB9A7B5SGC45YD9';
        $legacy = $normalizer->normalize('section-id', [
            'heading' => 'Story', 'body' => 'Legacy',
            'media' => ['assetId' => $mediaId, 'focalPoint' => ['x' => 0.2, 'y' => 0.7], 'zoom' => 1.5],
        ]);

        $this->assertSame('story-legacy-section-id', $legacy['elements'][0]['id']);
        $this->assertSame($mediaId, $legacy['elements'][0]['media']['mediaId']);
        $this->assertSame(['focalPoint' => ['x' => 0.2, 'y' => 0.7], 'zoom' => 1.5], $legacy['mediaFraming']['story-legacy-section-id']);

        $blocks = $normalizer->normalize('section-id', ['heading' => 'Story', 'intro' => null, 'blocks' => [
            ['id' => 'second', 'heading' => null, 'body' => 'Two', 'media' => ['assetId' => $mediaId]],
            ['id' => 'first', 'heading' => 'One', 'body' => 'One'],
        ]]);
        $this->assertSame(['second', 'first'], array_column($blocks['elements'], 'id'));
        $this->assertArrayNotHasKey('heading', $blocks['elements'][0]);
        $this->assertSame($blocks, $normalizer->normalize('section-id', $blocks));
    }

    public function test_v2_normalization_preserves_semantic_values_and_is_idempotent(): void
    {
        $content = ['heading' => 'Story', 'intro' => null, 'elements' => [
            ['id' => 'one', 'type' => 'narrativeBlock', 'body' => 'Body'],
        ], 'mediaFraming' => []];
        $normalizer = app(StoryContentNormalizer::class);

        $this->assertSame($content, $normalizer->normalize('ignored', $content));
        $this->assertSame($content, $normalizer->normalize('ignored', $normalizer->normalize('ignored', $content)));
    }

    public function test_story_header_visibility_is_sparse_and_normalizes_without_mutating_legacy_intent(): void
    {
        $normalizer = app(StoryContentNormalizer::class);
        $legacy = ['heading' => 'Story', 'intro' => 'Intro', 'elements' => [], 'mediaFraming' => []];

        $this->assertSame($legacy, $normalizer->normalize('story', $legacy));

        $hidden = [...$legacy, 'eyebrow' => 'Our story', 'eyebrowIsHidden' => true, 'headingIsHidden' => true, 'introIsHidden' => false];
        $this->assertSame($hidden, $normalizer->normalize('story', $hidden));
        $this->assertSame($hidden, app(WebsiteSectionContentValidator::class)->validate('story', $hidden));

        $emptyEyebrow = [...$legacy, 'eyebrow' => null, 'eyebrowIsHidden' => false];
        $this->assertSame($emptyEyebrow, $normalizer->normalize('story', $emptyEyebrow));
    }

    public function test_story_structure_order_is_sparse_and_accepts_a_complete_permutation(): void
    {
        $normalizer = app(StoryContentNormalizer::class);
        $legacy = ['heading' => 'Story', 'intro' => null, 'elements' => [], 'mediaFraming' => []];
        $this->assertSame($legacy, $normalizer->normalize('story', $legacy));
        $this->assertArrayNotHasKey('structureOrder', $normalizer->normalize('story', $legacy));

        $content = [...$legacy, 'elements' => [
            ['id' => 'first', 'type' => 'narrativeBlock', 'body' => 'First'],
            ['id' => 'second', 'type' => 'narrativeBlock', 'body' => 'Second'],
        ], 'structureOrder' => [
            'story:heading', 'narrative:first', 'story:eyebrow', 'story:intro', 'narrative:second',
        ]];
        $this->assertSame($content, $normalizer->normalize('story', $content));
        $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate('story', $content));

        $malformed = [...$legacy, 'structureOrder' => ['story:heading']];
        $this->assertSame($legacy, $normalizer->normalize('story', $malformed));
    }

    public function test_media_reference_scanning_covers_all_three_raw_generations(): void
    {
        $references = app(WebsiteSectionMediaReferences::class);
        $ids = ['01M0Q08NQ9XJB9A7B5SGC45YD9', '01M0Q08NQ9XJB9A7B5SGC45YDA', '01M0Q08NQ9XJB9A7B5SGC45YDB'];

        $this->assertSame($ids[0], $references->extract('story', ['media' => ['assetId' => $ids[0]]])[0]['assetId']);
        $this->assertSame($ids[1], $references->extract('story', ['blocks' => [['id' => 'old', 'media' => ['assetId' => $ids[1]]]]])[0]['assetId']);
        $this->assertSame($ids[2], $references->extract('story', ['elements' => [[
            'id' => 'new', 'type' => 'narrativeBlock', 'media' => ['type' => 'image', 'mediaId' => $ids[2]],
        ]], 'mediaFraming' => ['new' => ['zoom' => 1.5]]])[0]['assetId']);
    }

    public function test_canonical_story_validation_accepts_v2(): void
    {
        $content = ['heading' => '', 'intro' => null, 'elements' => [
            ['id' => 'one', 'type' => 'narrativeBlock', 'body' => 'Body'],
        ], 'mediaFraming' => []];

        $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate('story', $content));
    }

    public function test_story_singleton_appearance_is_sparse_preserved_and_strictly_tokenized(): void
    {
        $content = [
            'heading' => 'Story',
            'intro' => 'Intro',
            'elements' => [],
            'mediaFraming' => [],
            'singletonAppearance' => [
                'eyebrow' => ['fontFamilyId' => 'inter', 'fontSize' => ['mobile' => 's'], 'colorId' => 'terracotta-accent', 'alignment' => 'center'],
                'heading' => ['fontFamilyId' => 'editorial-serif', 'lineSpacing' => 'tight', 'letterSpacing' => 'wide', 'alignment' => 'center'],
                'intro' => ['alignment' => 'end'],
            ],
        ];

        $this->assertSame($content, app(WebsiteSectionContentValidator::class)->validate('story', $content));
        $this->assertSame($content, app(StoryContentNormalizer::class)->normalize('story', $content));

        foreach ([
            ['eyebrow', 'alignment', 'left'],
            ['heading', 'fontSize', ['desktop' => 'xxl']],
            ['heading', 'lineSpacing', 'loose'],
            ['intro', 'letterSpacing', 'extra-wide'],
            ['intro', 'alignment', 'left'],
        ] as [$field, $key, $value]) {
            try {
                app(WebsiteSectionContentValidator::class)->validate('story', [
                    ...$content,
                    'singletonAppearance' => [$field => [$key => $value]],
                ]);
                $this->fail("Expected {$field}.{$key} to fail validation.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('invalidStories')]
    public function test_story_rejects_legacy_disallowed_and_invalid_framing(array $content): void
    {
        $this->expectException(ValidationException::class);
        app(WebsiteSectionContentValidator::class)->validate('story', $content);
    }

    public static function invalidStories(): array
    {
        $base = ['heading' => '', 'intro' => null, 'elements' => [], 'mediaFraming' => []];

        return [
            'blocks' => [[...$base, 'blocks' => []]],
            'pre-block body' => [[...$base, 'body' => 'legacy']],
            'unknown element' => [[...$base, 'elements' => [['id' => 'one', 'type' => 'unknown', 'body' => '']]]],
            'Story-disallowed valid element' => [[...$base, 'elements' => [['id' => 'one', 'type' => 'text', 'text' => 'Text']]]],
            'duplicate IDs' => [[...$base, 'elements' => [
                ['id' => 'one', 'type' => 'narrativeBlock', 'body' => ''],
                ['id' => 'one', 'type' => 'narrativeBlock', 'body' => ''],
            ]]],
            'more than 20' => [[...$base, 'elements' => array_fill(0, 21, ['id' => 'one', 'type' => 'narrativeBlock', 'body' => ''])]],
            'orphan framing' => [[...$base, 'mediaFraming' => ['missing' => ['zoom' => 1.5]]]],
            'framing without media' => [[...$base, 'elements' => [['id' => 'one', 'type' => 'narrativeBlock', 'body' => '']], 'mediaFraming' => ['one' => ['zoom' => 1.5]]]],
            'invalid framing range' => [[...$base, 'elements' => [['id' => 'one', 'type' => 'narrativeBlock', 'body' => '', 'media' => ['type' => 'image', 'mediaId' => '01M0Q08NQ9XJB9A7B5SGC45YD9']]], 'mediaFraming' => ['one' => ['zoom' => 4]]]],
            'duplicate structure ref' => [[...$base, 'structureOrder' => ['story:eyebrow', 'story:heading', 'story:heading']]],
            'missing singleton structure ref' => [[...$base, 'structureOrder' => ['story:eyebrow', 'story:heading']]],
            'unknown structure ref' => [[...$base, 'structureOrder' => ['story:eyebrow', 'story:heading', 'story:unknown']]],
            'missing block structure ref' => [[...$base, 'elements' => [['id' => 'one', 'type' => 'narrativeBlock', 'body' => '']], 'structureOrder' => ['story:eyebrow', 'story:heading', 'story:intro']]],
            'extra block structure ref' => [[...$base, 'structureOrder' => ['story:eyebrow', 'story:heading', 'story:intro', 'narrative:missing']]],
            'narrative projection mismatch' => [[...$base, 'elements' => [
                ['id' => 'one', 'type' => 'narrativeBlock', 'body' => ''],
                ['id' => 'two', 'type' => 'narrativeBlock', 'body' => ''],
            ], 'structureOrder' => ['story:eyebrow', 'story:heading', 'story:intro', 'narrative:two', 'narrative:one']]],
        ];
    }
}
