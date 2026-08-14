<?php

namespace App\Actions\Websites;

use App\Models\WebsiteSection;
use App\Website\WebsiteSectionContentValidator;

final class UpdateWebsiteSectionContent
{
    public function __construct(private readonly WebsiteSectionContentValidator $validator) {}

    /** @param array<string, mixed> $content */
    public function handle(WebsiteSection $section, array $content): WebsiteSection
    {
        $section->content = $this->validator->validate($section->type, $content);
        $section->save();

        return $section;
    }
}
