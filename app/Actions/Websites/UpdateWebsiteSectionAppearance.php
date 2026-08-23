<?php

namespace App\Actions\Websites;

use App\Models\WebsiteSection;
use App\Website\WebsiteSectionAppearance;
use App\Website\WebsiteTemplateDefinition;
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
        $presentation = $template->presentationCapabilityFor($section->type);
        if (array_key_exists('presentation', $appearance)) {
            if ($presentation === null || ! in_array($appearance['presentation'], array_column($presentation['options'], 'key'), true)) {
                throw ValidationException::withMessages([
                    'appearance.presentation' => 'The selected presentation is invalid for this Section.',
                ]);
            }
            $expectedKeys[] = 'presentation';
        }
        $activePresentation = $appearance['presentation'] ?? $presentation['default'] ?? null;
        $controls = is_string($activePresentation) ? $template->mediaControlsFor($section->type, $activePresentation) : [];
        foreach ($template->mediaControlSettings() as $setting => $group) {
            if (! array_key_exists($setting, $appearance)) {
                continue;
            }
            $allowed = array_column($controls[$group]['options'] ?? [], 'key');
            if (! is_string($appearance[$setting]) || ! in_array($appearance[$setting], $allowed, true)) {
                throw ValidationException::withMessages([
                    "appearance.{$setting}" => "The selected {$setting} is not supported by this presentation.",
                ]);
            }
            $expectedKeys[] = $setting;
        }
        if (array_key_exists('mediaSpacing', $appearance)) {
            $spacing = $controls['mediaSpacing'] ?? null;
            $sides = ['top', 'right', 'bottom', 'left'];
            $actualSides = is_array($appearance['mediaSpacing']) ? array_keys($appearance['mediaSpacing']) : [];
            sort($actualSides);
            $expectedSides = $sides;
            sort($expectedSides);
            $allowed = array_column($spacing['options'] ?? [], 'key');
            if ($spacing === null || ! is_array($appearance['mediaSpacing']) || $actualSides !== $expectedSides) {
                throw ValidationException::withMessages([
                    'appearance.mediaSpacing' => 'Provide all supported media spacing sides without extra properties.',
                ]);
            }
            foreach ($sides as $side) {
                if (! is_string($appearance['mediaSpacing'][$side]) || ! in_array($appearance['mediaSpacing'][$side], $allowed, true)) {
                    throw ValidationException::withMessages([
                        "appearance.mediaSpacing.{$side}" => "The selected {$side} media spacing is not supported by this presentation.",
                    ]);
                }
            }
            $expectedKeys[] = 'mediaSpacing';
        }
        if (array_key_exists('overlayStrength', $appearance)) {
            $overlay = $controls['overlayStrength'] ?? null;
            if ($overlay === null || ! is_numeric($appearance['overlayStrength']) || $appearance['overlayStrength'] < $overlay['min'] || $appearance['overlayStrength'] > $overlay['max']) {
                throw ValidationException::withMessages([
                    'appearance.overlayStrength' => 'The selected overlay strength is not supported by this presentation.',
                ]);
            }
            $appearance['overlayStrength'] = (float) $appearance['overlayStrength'];
            $expectedKeys[] = 'overlayStrength';
        }
        if (array_key_exists('responsive', $appearance)) {
            $this->validateResponsiveOverrides($template, $section->type, $activePresentation, $appearance['responsive'], $options, $controls);
            $expectedKeys[] = 'responsive';
        }
        sort($expectedKeys);
        sort($actualKeys);

        if ($actualKeys !== $expectedKeys) {
            throw ValidationException::withMessages([
                'appearance' => 'Provide the complete supported Section appearance.',
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

        if (isset($appearance['responsive'])) {
            foreach ($appearance['responsive'] as $viewport => &$override) {
                foreach ($override as $setting => $value) {
                    if ($value === $template->responsiveDefaultFor($section->type, $activePresentation, $viewport, $setting)) {
                        unset($override[$setting]);
                    }
                }
            }
            unset($override);
            $appearance['responsive'] = array_filter(
                $appearance['responsive'],
                fn (array $override): bool => $override !== [],
            );
            if ($appearance['responsive'] === []) {
                unset($appearance['responsive']);
            }
        }

        $section->appearance = $appearance;
        $section->save();

        return $section;
    }

    /**
     * @param  array<string, mixed>  $responsive
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $controls
     */
    private function validateResponsiveOverrides(WebsiteTemplateDefinition $template, string $sectionType, ?string $presentation, array $responsive, array $options, array $controls): void
    {
        foreach ($responsive as $viewport => $override) {
            if (! in_array($viewport, WebsiteSectionAppearance::RESPONSIVE_VIEWPORTS, true) || ! is_array($override)) {
                throw ValidationException::withMessages([
                    "appearance.responsive.{$viewport}" => 'The selected responsive viewport is invalid.',
                ]);
            }

            foreach ($override as $setting => $value) {
                if (! in_array($setting, WebsiteSectionAppearance::RESPONSIVE_SETTINGS, true)) {
                    throw ValidationException::withMessages([
                        "appearance.responsive.{$viewport}.{$setting}" => 'This responsive appearance property is not supported.',
                    ]);
                }

                if ($setting === 'mediaSpacing') {
                    $this->validateResponsiveSpacing($template, $sectionType, $presentation, $viewport, $value, $controls);

                    continue;
                }

                $baseOptions = match ($setting) {
                    'headingAlignment' => $options['headingAlignments'] ?? [],
                    'bodyAlignment' => $options['bodyAlignments'] ?? [],
                    'mediaPlacement' => $controls['mediaPlacements']['options'] ?? [],
                    'mediaSize' => $controls['mediaSizes']['options'] ?? [],
                    'mediaContentGap' => $controls['mediaContentGaps']['options'] ?? [],
                };
                $viewportControl = $template->responsiveControlFor($sectionType, $presentation, $viewport, $setting);
                $allowed = array_column($viewportControl['options'] ?? $baseOptions, 'key');
                if (! is_string($value) || ! in_array($value, $allowed, true)) {
                    throw ValidationException::withMessages([
                        "appearance.responsive.{$viewport}.{$setting}" => "The selected {$setting} is not supported for this viewport.",
                    ]);
                }
            }
        }
    }

    /** @param array<string, mixed> $controls */
    private function validateResponsiveSpacing(WebsiteTemplateDefinition $template, string $sectionType, ?string $presentation, string $viewport, mixed $value, array $controls): void
    {
        $sides = ['top', 'right', 'bottom', 'left'];
        $actualSides = is_array($value) ? array_keys($value) : [];
        sort($actualSides);
        $expectedSides = $sides;
        sort($expectedSides);
        $viewportControl = $template->responsiveControlFor($sectionType, $presentation, $viewport, 'mediaSpacing');
        $baseControl = $controls['mediaSpacing'] ?? null;
        $allowed = array_column($viewportControl['options'] ?? $baseControl['options'] ?? [], 'key');

        if ($baseControl === null || ! is_array($value) || $actualSides !== $expectedSides) {
            throw ValidationException::withMessages([
                "appearance.responsive.{$viewport}.mediaSpacing" => 'Provide all supported media spacing sides without extra properties.',
            ]);
        }

        foreach ($sides as $side) {
            if (! is_string($value[$side]) || ! in_array($value[$side], $allowed, true)) {
                throw ValidationException::withMessages([
                    "appearance.responsive.{$viewport}.mediaSpacing.{$side}" => "The selected {$side} media spacing is not supported for this viewport.",
                ]);
            }
        }
    }
}
