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
            'appearance' => ['required', 'array:headingAlignment,bodyAlignment,backgroundTreatment,emphasis'],
            'appearance.headingAlignment' => ['required', 'string'],
            'appearance.bodyAlignment' => ['required', 'string'],
            'appearance.backgroundTreatment' => ['required', 'string'],
            'appearance.emphasis' => ['required', 'string'],
        ];
    }
}
