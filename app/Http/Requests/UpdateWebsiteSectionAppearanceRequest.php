<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteSectionAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appearance' => ['required', 'array:headingAlignment,bodyAlignment,backgroundTreatment,emphasis,presentation,mediaPlacement,mediaSize,frameStyle,cornerStyle,shadowStyle,overlayStrength,foregroundColor,mediaSpacing,mediaContentGap,responsive'],
            'appearance.headingAlignment' => ['required', 'string'],
            'appearance.bodyAlignment' => ['required', 'string'],
            'appearance.backgroundTreatment' => ['required', 'string'],
            'appearance.emphasis' => ['required', 'string'],
            'appearance.presentation' => ['sometimes', 'string'],
            'appearance.mediaPlacement' => ['sometimes', 'string'],
            'appearance.mediaSize' => ['sometimes', 'string'],
            'appearance.frameStyle' => ['sometimes', 'string'],
            'appearance.cornerStyle' => ['sometimes', 'string'],
            'appearance.shadowStyle' => ['sometimes', 'string'],
            'appearance.overlayStrength' => ['sometimes', 'numeric'],
            'appearance.foregroundColor' => ['sometimes', 'string'],
            'appearance.mediaSpacing' => ['sometimes', 'array:top,right,bottom,left'],
            'appearance.mediaSpacing.top' => ['required_with:appearance.mediaSpacing', 'string'],
            'appearance.mediaSpacing.right' => ['required_with:appearance.mediaSpacing', 'string'],
            'appearance.mediaSpacing.bottom' => ['required_with:appearance.mediaSpacing', 'string'],
            'appearance.mediaSpacing.left' => ['required_with:appearance.mediaSpacing', 'string'],
            'appearance.mediaContentGap' => ['sometimes', 'string'],
            'appearance.responsive' => ['sometimes', 'array:tablet,mobile', 'min:1'],
            'appearance.responsive.tablet' => ['sometimes', 'array:mediaPlacement,mediaSize,mediaContentGap,headingAlignment,bodyAlignment,mediaSpacing', 'min:1'],
            'appearance.responsive.mobile' => ['sometimes', 'array:mediaPlacement,mediaSize,mediaContentGap,headingAlignment,bodyAlignment,mediaSpacing', 'min:1'],
            'appearance.responsive.*.mediaPlacement' => ['sometimes', 'string'],
            'appearance.responsive.*.mediaSize' => ['sometimes', 'string'],
            'appearance.responsive.*.mediaContentGap' => ['sometimes', 'string'],
            'appearance.responsive.*.headingAlignment' => ['sometimes', 'string'],
            'appearance.responsive.*.bodyAlignment' => ['sometimes', 'string'],
            'appearance.responsive.*.mediaSpacing' => ['sometimes', 'array:top,right,bottom,left'],
            'appearance.responsive.*.mediaSpacing.top' => ['required_with:appearance.responsive.*.mediaSpacing', 'string'],
            'appearance.responsive.*.mediaSpacing.right' => ['required_with:appearance.responsive.*.mediaSpacing', 'string'],
            'appearance.responsive.*.mediaSpacing.bottom' => ['required_with:appearance.responsive.*.mediaSpacing', 'string'],
            'appearance.responsive.*.mediaSpacing.left' => ['required_with:appearance.responsive.*.mediaSpacing', 'string'],
        ];
    }
}
