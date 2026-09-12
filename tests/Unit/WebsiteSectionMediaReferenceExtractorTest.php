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
        ], $this->extractor->extract('section', $type, ['backgroundMedia' => ['assetId' => 'media-one']]));
    }

    public static function sectionMediaCases(): array
    {
        return [['hero']];
    }

    public function test_extracts_direct_and_nested_media_elements_but_not_direct_video_urls(): void
    {
        $content = ['childFlow' => ['elements' => [
            ['id' => 'direct', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'one', 'type' => 'image', 'mediaId' => 'media-one', 'alt' => 'One']]],
            ['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [
                ['id' => 'nested', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'two', 'type' => 'image', 'mediaId' => 'media-two', 'alt' => 'Two']]],
                ['id' => 'video', 'type' => 'media', 'editorName' => 'Media 1', 'items' => [['id' => 'clip', 'type' => 'video', 'url' => 'https://example.com/video.mp4']]],
            ]],
        ]]];

        $this->assertSame([
            ['mediaId' => 'media-one', 'reference' => ['type' => 'sectionMedia']],
            ['mediaId' => 'media-two', 'reference' => ['type' => 'sectionMedia']],
        ], $this->extractor->extract('blank-section', 'blank', $content));
    }

    public function test_extracts_direct_and_nested_group_background_media(): void
    {
        $content = ['childFlow' => ['elements' => [[
            'id' => 'outer', 'type' => 'compositionGroup', 'backgroundMedia' => ['assetId' => 'group-one', 'responsive' => ['tablet' => ['assetId' => 'group-tablet', 'zoom' => .7], 'mobile' => ['assetId' => 'group-mobile', 'zoom' => .4]]], 'children' => [[
                'id' => 'inner', 'type' => 'compositionGroup', 'backgroundMedia' => ['assetId' => 'group-two'], 'children' => [],
            ]],
        ]]]];
        $this->assertSame([
            ['mediaId' => 'group-one', 'reference' => ['type' => 'sectionMedia', 'elementId' => 'outer']],
            ['mediaId' => 'group-tablet', 'reference' => ['type' => 'sectionMedia', 'elementId' => 'outer']],
            ['mediaId' => 'group-mobile', 'reference' => ['type' => 'sectionMedia', 'elementId' => 'outer']],
            ['mediaId' => 'group-two', 'reference' => ['type' => 'sectionMedia', 'elementId' => 'inner']],
        ], $this->extractor->extract('blank', 'blank', $content));
    }

    public function test_extracts_people_block_media_from_blank_and_group(): void
    {
        $people = [
            'id' => 'people', 'type' => 'people', 'editorName' => 'People 1',
            'groups' => [[
                'id' => 'friends', 'name' => 'Friends',
                'people' => [['id' => 'alex', 'name' => 'Alex', 'media' => ['assetId' => 'media-one']]],
            ]],
        ];
        $content = ['childFlow' => ['elements' => [['id' => 'group', 'type' => 'compositionGroup', 'editorName' => 'Group 1', 'children' => [$people]]]]];
        $this->assertSame([
            ['mediaId' => 'media-one', 'reference' => ['type' => 'person', 'personId' => 'alex', 'label' => 'Alex', 'groupId' => 'friends', 'groupLabel' => 'Friends']],
        ], $this->extractor->extract('blank', 'blank', $content));
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
            ['gallery', ['items' => [['mediaId' => 'unwired']]]],
        ];
    }
}
