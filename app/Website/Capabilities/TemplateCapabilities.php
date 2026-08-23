<?php

namespace App\Website\Capabilities;

final readonly class TemplateCapabilities
{
    /**
     * @param  list<string>  $elements
     * @param  list<SectionCapability>  $sections
     */
    public function __construct(
        public GlobalDesignCapability $globalDesign,
        public array $elements,
        public array $sections,
    ) {}
}
