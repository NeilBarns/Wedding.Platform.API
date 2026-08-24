<?php

namespace App\Website\Capabilities;

final readonly class ContextDefaultsIntent
{
    public function __construct(
        public ?string $headingFontId = null,
        public ?string $bodyFontId = null,
        public ?string $headingColorId = null,
        public ?string $bodyColorId = null,
        public ?string $accentColorId = null,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter(get_object_vars($this), fn (?string $value): bool => $value !== null);
    }
}
