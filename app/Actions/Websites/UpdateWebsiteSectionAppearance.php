<?php

namespace App\Actions\Websites;

use App\Models\WebsiteSection;
use App\Website\Capabilities\AppearanceControlCapability;
use App\Website\Capabilities\AppearanceControlType;
use App\Website\Capabilities\WebsiteCapabilityResolver;
use App\Website\WebsiteSectionAppearance;
use Illuminate\Validation\ValidationException;

final class UpdateWebsiteSectionAppearance
{
    private const ROOT_OPTION_CONTROLS = ['mediaPlacement', 'mediaSize', 'frameStyle', 'cornerStyle', 'shadowStyle', 'foregroundColor', 'mediaContentGap'];

    public function __construct(private readonly WebsiteCapabilityResolver $capabilities) {}

    /** @param array<string, mixed> $appearance */
    public function handle(WebsiteSection $section, array $appearance): WebsiteSection
    {
        $storedDesignDefaults = $section->appearance['designDefaults'] ?? null;
        $section->loadMissing('website');
        $templateKey = $section->website->template_key;
        $sectionCapability = $this->capabilities->section($templateKey, $section->type);
        if ($sectionCapability === null) {
            throw ValidationException::withMessages(['appearance' => 'Section appearance is not supported by the assigned Template.']);
        }

        $expectedKeys = ['headingAlignment', 'bodyAlignment', 'backgroundTreatment', 'emphasis'];
        $actualKeys = array_keys($appearance);
        $activePresentation = $sectionCapability->defaultPresentation;
        if (array_key_exists('presentation', $appearance)) {
            if (! is_string($appearance['presentation']) || $this->capabilities->presentation($templateKey, $section->type, $appearance['presentation']) === null) {
                throw ValidationException::withMessages(['appearance.presentation' => 'The selected presentation is invalid for this Section.']);
            }
            $activePresentation = $appearance['presentation'];
            $expectedKeys[] = 'presentation';
        }

        $desktopControls = $this->controlsById($templateKey, $section->type, $activePresentation, 'desktop');
        foreach (self::ROOT_OPTION_CONTROLS as $setting) {
            if (! array_key_exists($setting, $appearance)) {
                continue;
            }
            if (! $this->validOption($desktopControls[$setting] ?? null, $appearance[$setting])) {
                throw ValidationException::withMessages(["appearance.{$setting}" => "The selected {$setting} is not supported by this presentation."]);
            }
            $expectedKeys[] = $setting;
        }

        if (array_key_exists('mediaSpacing', $appearance)) {
            $this->validateSpacing($desktopControls['mediaSpacing'] ?? null, $appearance['mediaSpacing'], 'appearance.mediaSpacing', false);
            $expectedKeys[] = 'mediaSpacing';
        }
        if (array_key_exists('overlayStrength', $appearance)) {
            $control = $desktopControls['overlayStrength'] ?? null;
            if ($control?->type !== AppearanceControlType::Number || ! is_numeric($appearance['overlayStrength'])
                || $appearance['overlayStrength'] < $control->minimum || $appearance['overlayStrength'] > $control->maximum) {
                throw ValidationException::withMessages(['appearance.overlayStrength' => 'The selected overlay strength is not supported by this presentation.']);
            }
            $appearance['overlayStrength'] = (float) $appearance['overlayStrength'];
            $expectedKeys[] = 'overlayStrength';
        }
        if (array_key_exists('responsive', $appearance)) {
            $this->validateResponsiveOverrides($templateKey, $section->type, $activePresentation, $appearance['responsive']);
            $expectedKeys[] = 'responsive';
        }

        sort($expectedKeys);
        sort($actualKeys);
        if ($actualKeys !== $expectedKeys) {
            throw ValidationException::withMessages(['appearance' => 'Provide the complete supported Section appearance.']);
        }

        foreach (['headingAlignment', 'bodyAlignment', 'backgroundTreatment', 'emphasis'] as $setting) {
            if ($section->type === 'story' && $setting === 'emphasis') {
                if (($appearance[$setting] ?? null) !== 'inherit') {
                    throw ValidationException::withMessages(['appearance.emphasis' => 'Story emphasis is no longer an authored appearance control.']);
                }

                continue;
            }
            if (! $this->validOption($desktopControls[$setting] ?? null, $appearance[$setting])) {
                throw ValidationException::withMessages(["appearance.{$setting}" => "The selected {$setting} is invalid for this Section."]);
            }
        }

        if ($section->type === 'story') {
            $appearance = $this->preserveLegacyStoryAppearance($section->appearance, $appearance);
        }

        if (isset($appearance['responsive'])) {
            foreach ($appearance['responsive'] as $viewport => &$override) {
                $viewportControls = $this->controlsById($templateKey, $section->type, $activePresentation, $viewport);
                foreach ($override as $setting => $value) {
                    if ($value === ($viewportControls[$setting]->default ?? null)) {
                        unset($override[$setting]);
                    }
                }
            }
            unset($override);
            $appearance['responsive'] = array_filter($appearance['responsive'], fn (array $override): bool => $override !== []);
            if ($appearance['responsive'] === []) {
                unset($appearance['responsive']);
            }
        }

        if ($storedDesignDefaults !== null) {
            $appearance['designDefaults'] = is_array($storedDesignDefaults) && $storedDesignDefaults === []
                ? new \stdClass
                : $storedDesignDefaults;
        }
        $section->appearance = $appearance;
        $section->save();

        return $section;
    }

    /** @param array<string, mixed> $stored @param array<string, mixed> $authored */
    private function preserveLegacyStoryAppearance(array $stored, array $authored): array
    {
        foreach (['emphasis', 'presentation', 'mediaPlacement', 'mediaSize', 'frameStyle', 'cornerStyle', 'shadowStyle', 'overlayStrength', 'foregroundColor', 'mediaSpacing', 'mediaContentGap'] as $key) {
            if (array_key_exists($key, $stored)) {
                $authored[$key] = $stored[$key];
            } else {
                unset($authored[$key]);
            }
        }
        foreach ($stored['responsive'] ?? [] as $viewport => $override) {
            if (! is_array($override)) {
                continue;
            }
            $legacy = array_diff_key($override, array_flip(['headingAlignment', 'bodyAlignment']));
            if ($legacy !== []) {
                $authored['responsive'][$viewport] = [...$legacy, ...($authored['responsive'][$viewport] ?? [])];
            }
        }

        return $authored;
    }

    /** @param array<string, mixed> $responsive */
    private function validateResponsiveOverrides(string $templateKey, string $sectionType, ?string $presentation, array $responsive): void
    {
        foreach ($responsive as $viewport => $override) {
            if (! in_array($viewport, WebsiteSectionAppearance::RESPONSIVE_VIEWPORTS, true) || ! is_array($override)) {
                throw ValidationException::withMessages(["appearance.responsive.{$viewport}" => 'The selected responsive viewport is invalid.']);
            }
            $controls = $this->controlsById($templateKey, $sectionType, $presentation, $viewport);
            foreach ($override as $setting => $value) {
                if (! in_array($setting, WebsiteSectionAppearance::RESPONSIVE_SETTINGS, true)) {
                    throw ValidationException::withMessages(["appearance.responsive.{$viewport}.{$setting}" => 'This responsive appearance property is not supported.']);
                }
                if ($setting === 'mediaSpacing') {
                    $this->validateSpacing($controls[$setting] ?? null, $value, "appearance.responsive.{$viewport}.mediaSpacing", true);
                } elseif (! $this->validOption($controls[$setting] ?? null, $value)) {
                    throw ValidationException::withMessages(["appearance.responsive.{$viewport}.{$setting}" => "The selected {$setting} is not supported for this viewport."]);
                }
            }
        }
    }

    private function validateSpacing(?AppearanceControlCapability $control, mixed $value, string $path, bool $responsive): void
    {
        $sides = ['top', 'right', 'bottom', 'left'];
        $actualSides = is_array($value) ? array_keys($value) : [];
        sort($actualSides);
        $expectedSides = $sides;
        sort($expectedSides);
        if ($control?->type !== AppearanceControlType::Spacing || ! is_array($value) || $actualSides !== $expectedSides) {
            throw ValidationException::withMessages([$path => 'Provide all supported media spacing sides without extra properties.']);
        }

        $allowed = array_column($control->options, 'key');
        foreach ($sides as $side) {
            if (! is_string($value[$side]) || ! in_array($value[$side], $allowed, true)) {
                throw ValidationException::withMessages([
                    "{$path}.{$side}" => $responsive
                        ? "The selected {$side} media spacing is not supported for this viewport."
                        : "The selected {$side} media spacing is not supported by this presentation.",
                ]);
            }
        }
    }

    private function validOption(?AppearanceControlCapability $control, mixed $value): bool
    {
        return $control?->type === AppearanceControlType::Option && is_string($value)
            && in_array($value, array_column($control->options, 'key'), true);
    }

    /** @return array<string, AppearanceControlCapability> */
    private function controlsById(string $templateKey, string $sectionType, ?string $presentation, string $viewport): array
    {
        $controls = $this->capabilities->controlsForViewport($templateKey, $sectionType, $presentation, $viewport) ?? [];

        return collect($controls)->keyBy('id')->all();
    }
}
