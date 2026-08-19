<?php

namespace App\Actions\Media;

final readonly class MediaAssetUsage
{
    /** @param list<array{sectionId: string, type: string, displayName: string, context?: array{groupId: string, groupName: string, personId: string, personName: string}}> $websiteSections */
    public function __construct(public array $websiteSections) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function isInUse(): bool
    {
        return $this->websiteSections !== [];
    }

    /** @return array{isInUse: bool, website: array{sections: list<array{sectionId: string, type: string, displayName: string}>}} */
    public function toArray(): array
    {
        return [
            'isInUse' => $this->isInUse(),
            'website' => ['sections' => $this->websiteSections],
        ];
    }
}
