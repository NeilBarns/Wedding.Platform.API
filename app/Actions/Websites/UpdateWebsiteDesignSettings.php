<?php

namespace App\Actions\Websites;

use App\Models\Website;
use App\Website\Capabilities\GlobalDesignControlId;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteSchema;
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

        $expectedKeys = [...array_map(fn (GlobalDesignControlId $id): string => $id->value, GlobalDesignControlId::cases()), 'projectDefaults'];
        $actualKeys = array_keys($settings);
        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys) {
            throw ValidationException::withMessages(['designSettings' => 'Provide exactly colorTheme, fontSet, artStyle, and projectDefaults.']);
        }

        foreach (GlobalDesignControlId::cases() as $controlId) {
            $setting = $controlId->value;
            if (! is_string($settings[$setting]) || ! $this->capabilities->allowsGlobalDesignValue($website->template_key, $controlId, $settings[$setting])) {
                throw ValidationException::withMessages([
                    "designSettings.{$setting}" => "The selected {$setting} is invalid for this Template.",
                ]);
            }
        }

        if (! is_array($settings['projectDefaults'])) {
            throw ValidationException::withMessages(['designSettings.projectDefaults' => 'Project defaults must be an object.']);
        }
        $capability = $this->capabilities->projectDefaults($website->template_key);
        $allowed = [
            'headingFontId' => $capability->typography->headingFontIds,
            'bodyFontId' => $capability->typography->bodyFontIds,
            'headingColorId' => $capability->colors->headingColorIds,
            'bodyColorId' => $capability->colors->bodyColorIds,
            'accentColorId' => $capability->colors->accentColorIds,
        ];
        foreach ($settings['projectDefaults'] as $key => $value) {
            if (! array_key_exists($key, $allowed)) {
                throw ValidationException::withMessages(["designSettings.projectDefaults.{$key}" => 'This Project Design Default is not supported.']);
            }
            if (! is_string($value) || trim($value) === '' || ! in_array($value, $allowed[$key], true)) {
                throw ValidationException::withMessages(["designSettings.projectDefaults.{$key}" => 'The selected Project Design Default is invalid for this Template role.']);
            }
        }

        $settings['projectDefaults'] = (object) $settings['projectDefaults'];
        $website->design_settings = $settings;
        $website->schema_version = WebsiteSchema::CURRENT_SCHEMA_VERSION;
        $website->save();

        return $website;
    }
}
