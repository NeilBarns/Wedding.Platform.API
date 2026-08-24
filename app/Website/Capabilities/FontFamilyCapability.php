<?php

namespace App\Website\Capabilities;

final readonly class FontFamilyCapability
{
    /** @param list<TypographyRole> $allowedRoles */
    public function __construct(
        public string $id,
        public string $displayName,
        public array $allowedRoles,
    ) {}
}
