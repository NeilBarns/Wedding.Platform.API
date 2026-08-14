<?php

namespace App\Actions\Websites;

use App\Models\Website;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Validation\ValidationException;

final class UpdateWebsiteDesignSettings
{
    public function __construct(private readonly WebsiteTemplateRegistry $templates) {}

    /** @param array<string, mixed> $settings */
    public function handle(Website $website, array $settings): Website
    {
        $template = $this->templates->get($website->template_key);

        if ($template === null) {
            throw ValidationException::withMessages(['designSettings' => 'The assigned Template is not supported.']);
        }

        $expectedKeys = ['colorTheme', 'fontSet', 'artStyle'];
        $actualKeys = array_keys($settings);
        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys) {
            throw ValidationException::withMessages(['designSettings' => 'Provide exactly colorTheme, fontSet, and artStyle.']);
        }

        $optionGroups = [
            'colorTheme' => 'colorThemes',
            'fontSet' => 'fontSets',
            'artStyle' => 'artStyles',
        ];

        foreach ($optionGroups as $setting => $group) {
            $allowed = array_column($template->designOptions[$group], 'key');
            if (! is_string($settings[$setting]) || ! in_array($settings[$setting], $allowed, true)) {
                throw ValidationException::withMessages([
                    "designSettings.{$setting}" => "The selected {$setting} is invalid for this Template.",
                ]);
            }
        }

        $website->design_settings = $settings;
        $website->save();

        return $website;
    }
}
