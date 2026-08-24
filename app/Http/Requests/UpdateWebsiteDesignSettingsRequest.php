<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteDesignSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'designSettings' => ['required', 'array'],
            'designSettings.colorTheme' => ['required', 'string'],
            'designSettings.fontSet' => ['required', 'string'],
            'designSettings.artStyle' => ['required', 'string'],
            'designSettings.projectDefaults' => ['present', 'array'],
            'designSettings.projectDefaults.*' => ['string'],
        ];
    }
}
