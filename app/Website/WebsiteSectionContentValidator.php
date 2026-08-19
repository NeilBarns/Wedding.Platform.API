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
        array_walk_recursive($validated, function (mixed &$value, string|int $key) use ($sectionType): void {
            if ($value === null && $key !== 'media' && ! ($sectionType === 'people' && $key === 'role')) {
                $value = '';
            }
        });

        return $validated;
    }

    /** @return array<string, list<string>>|null */
    private function rulesFor(string $sectionType): ?array
    {
        return match ($sectionType) {
            'hero' => $this->singleMediaRules($this->stringContentRules(['headline' => 255, 'subheadline' => 500])),
            'date', 'dressCode' => $this->stringContentRules(['heading' => 255, 'description' => 5000]),
            'story' => $this->singleMediaRules($this->stringContentRules(['heading' => 255, 'body' => 10000])),
            'venue' => $this->singleMediaRules($this->stringContentRules([
                'heading' => 255,
                'name' => 255,
                'address' => 1000,
                'description' => 5000,
            ])),
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
            'people' => [
                'content' => ['required', 'array:heading,groups'],
                'content.heading' => ['present', 'nullable', 'string', 'max:255'],
                'content.groups' => ['present', 'array', 'max:30'],
                'content.groups.*' => ['required', 'array:id,name,people'],
                'content.groups.*.id' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/', 'distinct:strict'],
                'content.groups.*.name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
                'content.groups.*.people' => ['present', 'array', 'max:100'],
                'content.groups.*.people.*' => ['required', 'array:id,name,role,media'],
                'content.groups.*.people.*.id' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/', 'distinct:strict'],
                'content.groups.*.people.*.name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
                'content.groups.*.people.*.role' => ['sometimes', 'nullable', 'string', 'max:255'],
                'content.groups.*.people.*.media' => ['sometimes', 'nullable', 'array:assetId,focalPoint'],
                'content.groups.*.people.*.media.assetId' => ['required_with:content.groups.*.people.*.media', 'string', 'ulid'],
                'content.groups.*.people.*.media.focalPoint' => ['sometimes', 'array:x,y'],
                'content.groups.*.people.*.media.focalPoint.x' => ['required_with:content.groups.*.people.*.media.focalPoint', 'numeric', 'between:0,1'],
                'content.groups.*.people.*.media.focalPoint.y' => ['required_with:content.groups.*.people.*.media.focalPoint', 'numeric', 'between:0,1'],
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

    /** @param array<string, list<string>> $rules */
    private function singleMediaRules(array $rules): array
    {
        $rules['content'][1] .= ',media';
        $rules['content.media'] = ['sometimes', 'nullable', 'array:assetId,focalPoint'];
        $rules['content.media.assetId'] = ['required_with:content.media', 'string', 'ulid'];
        $rules['content.media.focalPoint'] = ['sometimes', 'array:x,y'];
        $rules['content.media.focalPoint.x'] = ['required_with:content.media.focalPoint', 'numeric', 'between:0,1'];
        $rules['content.media.focalPoint.y'] = ['required_with:content.media.focalPoint', 'numeric', 'between:0,1'];

        return $rules;
    }
}
