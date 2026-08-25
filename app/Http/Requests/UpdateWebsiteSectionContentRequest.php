<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWebsiteSectionContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schemaVersion' => ['sometimes', 'integer', 'in:2,3,4'],
            'content' => ['required', 'array'],
        ];
    }
}
