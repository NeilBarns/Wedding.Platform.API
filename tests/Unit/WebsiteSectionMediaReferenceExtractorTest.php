<?php

namespace Tests\Unit;

use App\Website\WebsiteSectionMediaReferenceExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebsiteSectionMediaReferenceExtractorTest extends TestCase
{
    private WebsiteSectionMediaReferenceExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new WebsiteSectionMediaReferenceExtractor;
    }

    #[DataProvider('sectionMediaCases')]
    public function test_extracts_active_section_media(string $type): void
    {
        $this->assertSame([
            ['mediaId' => 'media-one', 'reference' => ['type' => 'sectionMedia']],
        ], $this->extractor->extract('section', $type, ['media' => ['assetId' => 'media-one']]));
    }

    public static function sectionMediaCases(): array
    {
        return [['hero'], ['venue']];
    }

    public function test_extracts_pre_block_story_with_stable_synthetic_element_id(): void
    {
        $this->assertSame([
            ['mediaId' => 'media-one', 'reference' => ['type' => 'storyNarrativeBlock', 'elementId' => 'story-legacy-section-one']],
        ], $this->extractor->extract('section-one', 'story', ['media' => ['assetId' => 'media-one']]));
    }

    public function test_extracts_blocks_and_v2_story_with_canonical_context(): void
    {
        $blocks = ['blocks' => [
            ['id' => 'one', 'heading' => '  ', 'media' => ['assetId' => 'media-one']],
            ['id' => 'two', 'heading' => 'Proposal', 'media' => ['assetId' => 'media-two']],
        ]];
        $elements = ['elements' => [
            ['id' => 'one', 'type' => 'narrativeBlock', 'media' => ['type' => 'image', 'mediaId' => 'media-one']],
            ['id' => 'two', 'type' => 'narrativeBlock', 'heading' => 'Proposal', 'media' => ['type' => 'image', 'mediaId' => 'media-two']],
        ]];
        $expected = [
            ['mediaId' => 'media-one', 'reference' => ['type' => 'storyNarrativeBlock', 'elementId' => 'one']],
            ['mediaId' => 'media-two', 'reference' => ['type' => 'storyNarrativeBlock', 'elementId' => 'two', 'label' => 'Proposal']],
        ];

        $this->assertSame($expected, $this->extractor->extract('section', 'story', $blocks));
        $this->assertSame($expected, $this->extractor->extract('section', 'story', $elements));
    }

    public function test_extracts_people_semantic_context_in_source_order(): void
    {
        $content = ['groups' => [['id' => 'family', 'name' => 'Family', 'people' => [
            ['id' => 'jane', 'name' => 'Jane', 'media' => ['assetId' => 'media-one']],
            ['id' => 'alex', 'name' => '', 'media' => ['assetId' => 'media-two']],
        ]]]];

        $this->assertSame([
            ['mediaId' => 'media-one', 'reference' => ['type' => 'person', 'personId' => 'jane', 'label' => 'Jane', 'groupId' => 'family', 'groupLabel' => 'Family']],
            ['mediaId' => 'media-two', 'reference' => ['type' => 'person', 'personId' => 'alex', 'groupId' => 'family', 'groupLabel' => 'Family']],
        ], $this->extractor->extract('section', 'people', $content));
    }

    #[DataProvider('emptyAndMalformedCases')]
    public function test_skips_null_absent_and_malformed_references(string $type, array $content): void
    {
        $this->assertSame([], $this->extractor->extract('section', $type, $content));
    }

    public static function emptyAndMalformedCases(): array
    {
        return [
            ['hero', []],
            ['venue', ['media' => null]],
            ['story', ['media' => ['assetId' => 123]]],
            ['story', ['blocks' => [['id' => 123, 'media' => ['assetId' => 'media']]]]],
            ['story', ['elements' => [['id' => 'one', 'type' => 'text', 'media' => ['type' => 'image', 'mediaId' => 'media']]]]],
            ['people', ['groups' => [['people' => [['id' => 123, 'media' => ['assetId' => 'media']]]]]]],
            ['gallery', ['items' => [['mediaId' => 'unwired']]]],
        ];
    }
}
