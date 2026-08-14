<?php

namespace App\Website;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsiteSectionContentValidator
{
    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function validate(string $sectionType, array $content): array
    {
        $rules = $this->rulesFor($sectionType);

        if ($rules === null) {
            throw ValidationException::withMessages([
                'content' => "Section type [{$sectionType}] is not editable.",
            ]);
        }

        $validated = Validator::make(['content' => $content], $rules)->validate()['content'];
        array_walk_recursive($validated, function (mixed &$value): void {
            if ($value === null) {
                $value = '';
            }
        });

        return $validated;
    }

    /** @return array<string, list<string>>|null */
    private function rulesFor(string $sectionType): ?array
    {
        return match ($sectionType) {
            'hero' => $this->stringContentRules(['headline' => 255, 'subheadline' => 500]),
            'date', 'dressCode' => $this->stringContentRules(['heading' => 255, 'description' => 5000]),
            'story' => $this->stringContentRules(['heading' => 255, 'body' => 10000]),
            'venue' => $this->stringContentRules([
                'heading' => 255,
                'name' => 255,
                'address' => 1000,
                'description' => 5000,
            ]),
            'rsvp' => $this->stringContentRules([
                'heading' => 255,
                'description' => 5000,
                'buttonLabel' => 100,
            ]),
            'schedule' => [
                'content' => ['required', 'array:heading,items'],
                'content.heading' => ['present', 'nullable', 'string', 'max:255'],
                'content.items' => ['present', 'array', 'max:100'],
                'content.items.*' => ['required', 'array:time,title,description'],
                'content.items.*.time' => ['present', 'nullable', 'string', 'max:100'],
                'content.items.*.title' => ['present', 'nullable', 'string', 'max:255'],
                'content.items.*.description' => ['present', 'nullable', 'string', 'max:5000'],
            ],
            'gallery' => [
                'content' => ['required', 'array:heading,items'],
                'content.heading' => ['present', 'nullable', 'string', 'max:255'],
                'content.items' => ['present', 'array', 'size:0'],
            ],
            'faq' => [
                'content' => ['required', 'array:heading,items'],
                'content.heading' => ['present', 'nullable', 'string', 'max:255'],
                'content.items' => ['present', 'array', 'max:100'],
                'content.items.*' => ['required', 'array:question,answer'],
                'content.items.*.question' => ['present', 'nullable', 'string', 'max:1000'],
                'content.items.*.answer' => ['present', 'nullable', 'string', 'max:5000'],
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, int>  $fields
     * @return array<string, list<string>>
     */
    private function stringContentRules(array $fields): array
    {
        $rules = [
            'content' => ['required', 'array:'.implode(',', array_keys($fields))],
        ];

        foreach ($fields as $field => $maximum) {
            $rules["content.{$field}"] = ['present', 'nullable', 'string', "max:{$maximum}"];
        }

        return $rules;
    }
}
