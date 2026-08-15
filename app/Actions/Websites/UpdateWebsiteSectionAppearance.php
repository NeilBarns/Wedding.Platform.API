<?php

namespace App\Actions\Websites;

use App\Models\WebsiteSection;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Validation\ValidationException;

final class UpdateWebsiteSectionAppearance
{
    public function __construct(private readonly WebsiteTemplateRegistry $templates) {}

    /** @param array<string, mixed> $appearance */
    public function handle(WebsiteSection $section, array $appearance): WebsiteSection
    {
        $section->loadMissing('website');
        $template = $this->templates->get($section->website->template_key);
        $options = $template?->appearanceOptionsFor($section->type);

        if ($options === null) {
            throw ValidationException::withMessages([
                'appearance' => 'Section appearance is not supported by the assigned Template.',
            ]);
        }

        $optionGroups = [
            'headingAlignment' => 'headingAlignments',
            'bodyAlignment' => 'bodyAlignments',
            'backgroundTreatment' => 'backgroundTreatments',
            'emphasis' => 'emphasisOptions',
        ];
        $expectedKeys = array_keys($optionGroups);
        $actualKeys = array_keys($appearance);
        sort($expectedKeys);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            throw ValidationException::withMessages([
                'appearance' => 'Provide exactly headingAlignment, bodyAlignment, backgroundTreatment, and emphasis.',
            ]);
        }

        foreach ($optionGroups as $setting => $group) {
            $allowed = array_column($options[$group], 'key');
            if (! is_string($appearance[$setting]) || ! in_array($appearance[$setting], $allowed, true)) {
                throw ValidationException::withMessages([
                    "appearance.{$setting}" => "The selected {$setting} is invalid for this Section.",
                ]);
            }
        }

        $section->appearance = $appearance;
        $section->save();

        return $section;
    }
}
