<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateWebsiteSectionContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['content' => ['required', 'array']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            // Associative decoding loses the distinction between {} and [].
            // Check the original JSON before validating the decoded Divider tree.
            $body = json_decode($this->getContent());
            $visit = function (mixed $element, string $path) use (&$visit, $validator): void {
                if (! $element instanceof \stdClass) {
                    return;
                }
                if (($element->type ?? null) === 'divider' && property_exists($element, 'appearance') && ! $element->appearance instanceof \stdClass) {
                    $validator->errors()->add($path.'.appearance', 'Divider appearance must be a JSON object.');
                }
                if (($element->type ?? null) === 'compositionGroup' && is_array($element->children ?? null)) {
                    foreach ($element->children as $index => $child) {
                        $visit($child, $path.'.children.'.$index);
                    }
                }
            };
            $elements = $body->content->childFlow->elements ?? null;
            if (is_array($elements)) {
                foreach ($elements as $index => $element) {
                    $visit($element, 'content.childFlow.elements.'.$index);
                }
            }
        }];
    }
}
