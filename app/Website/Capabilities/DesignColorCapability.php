<?php

namespace App\Website\Capabilities;

final readonly class DesignColorCapability
{
    public function __construct(
        public string $id,
        public string $displayName,
        public string $value,
    ) {}
}
