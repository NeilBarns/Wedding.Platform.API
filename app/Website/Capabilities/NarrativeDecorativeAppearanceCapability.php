<?php

namespace App\Website\Capabilities;

final readonly class NarrativeDecorativeAppearanceCapability
{
    public function __construct(public array $textures, public array $patterns) {}

    public static function forTemplate(string $templateKey): self
    {
        return $templateKey === 'classic-filipiniana-v1'
            ? new self(['none', 'paper', 'fabric'], ['none', 'botanical'])
            : new self(['none'], ['none']);
    }
}
