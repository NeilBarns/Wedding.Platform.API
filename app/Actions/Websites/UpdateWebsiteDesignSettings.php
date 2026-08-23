<?php

namespace App\Actions\Websites;

use App\Models\Website;
use App\Website\Capabilities\GlobalDesignControlId;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use Illuminate\Validation\ValidationException;

final class UpdateWebsiteDesignSettings
{
    public function __construct(private readonly WebsiteCapabilityResolver $capabilities) {}

    /** @param array<string, mixed> $settings */
    public function handle(Website $website, array $settings): Website
    {
        if ($this->capabilities->globalDesign($website->template_key) === null) {
            throw ValidationException::withMessages(['designSettings' => 'The assigned Template is not supported.']);
        }

        $expectedKeys = array_map(fn (GlobalDesignControlId $id): string => $id->value, GlobalDesignControlId::cases());
        $actualKeys = array_keys($settings);
        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys) {
            throw ValidationException::withMessages(['designSettings' => 'Provide exactly colorTheme, fontSet, and artStyle.']);
        }

        foreach (GlobalDesignControlId::cases() as $controlId) {
            $setting = $controlId->value;
            if (! is_string($settings[$setting]) || ! $this->capabilities->allowsGlobalDesignValue($website->template_key, $controlId, $settings[$setting])) {
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
