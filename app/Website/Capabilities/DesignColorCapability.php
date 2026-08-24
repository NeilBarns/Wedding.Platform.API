<?php

namespace App\Website\Capabilities;

final readonly class DesignColorCapability
{
    /** @param  list<ProjectColorRole>  $allowedProjectRoles */
    public function __construct(
        public string $id,
        public string $displayName,
        public string $value,
        public array $allowedProjectRoles,
    ) {}
}
