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
        ];
    }
}
