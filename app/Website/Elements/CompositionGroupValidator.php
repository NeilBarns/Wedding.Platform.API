<?php

namespace App\Website\Elements;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CompositionGroupValidator
{
    private const CONTENT_TYPES = [
        'heading',
        'text',
        'divider',
        'quote',
        'cta',
        'narrativeBlock',
        'eventDate',
        'eventTime',
        'countdown',
    ];

    private const MEDIA_TYPES = ['image', 'mediaCollection'];

    /**
     * @param  array<string, mixed>  $group
     * @param  Closure(array<string, mixed>): array<string, mixed>  $validateLeaf
     * @return array<string, mixed>
     */
    public function validate(array $group, Closure $validateLeaf): array
    {
        $composition = $group['composition'] ?? null;
        if (! is_string($composition) || ! in_array($composition, ['flow', 'zoned'], true)) {
            throw ValidationException::withMessages([
                'element.composition' => 'The composition must be flow or zoned.',
            ]);
        }

        $validated = $composition === 'flow'
            ? $this->validateFlow($group, $validateLeaf)
            : $this->validateZoned($group, $validateLeaf);

        $this->assertUniqueTreeIds([$validated]);

        return $validated;
    }

    /** @param list<array<string, mixed>> $elements */
    public function assertUniqueTreeIds(array $elements): void
    {
        $seen = [];

        foreach ($elements as $element) {
            $this->collectId($element, $seen);
        }
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  Closure(array<string, mixed>): array<string, mixed>  $validateLeaf
     * @return array<string, mixed>
     */
    private function validateFlow(array $group, Closure $validateLeaf): array
    {
        $validated = Validator::make(['element' => $group], [
            'element' => ['required', 'array:id,type,composition,children'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:compositionGroup'],
            'element.composition' => ['required', 'in:flow'],
            'element.children' => ['present', 'array', 'list'],
            'element.children.*' => ['required', 'array'],
        ])->validate()['element'];

        $validated['id'] = trim($validated['id']);
        $validated['children'] = array_map($validateLeaf, $validated['children']);
        $this->assertAtMostOneMediaCollection($validated['children']);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  Closure(array<string, mixed>): array<string, mixed>  $validateLeaf
     * @return array<string, mixed>
     */
    private function validateZoned(array $group, Closure $validateLeaf): array
    {
        $validated = Validator::make(['element' => $group], [
            'element' => ['required', 'array:id,type,composition,zones'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:compositionGroup'],
            'element.composition' => ['required', 'in:zoned'],
            'element.zones' => ['required', 'array:media,content'],
            'element.zones.media' => ['present', 'array', 'list'],
            'element.zones.media.*' => ['required', 'array'],
            'element.zones.content' => ['present', 'array', 'list'],
            'element.zones.content.*' => ['required', 'array'],
        ])->validate()['element'];

        $validated['id'] = trim($validated['id']);
        $validated['zones']['media'] = $this->validateZone($validated['zones']['media'], self::MEDIA_TYPES, 'media', $validateLeaf);
        $validated['zones']['content'] = $this->validateZone($validated['zones']['content'], self::CONTENT_TYPES, 'content', $validateLeaf);
        $this->assertAtMostOneMediaCollection([
            ...$validated['zones']['media'],
            ...$validated['zones']['content'],
        ]);

        return $validated;
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @param  list<string>  $allowedTypes
     * @param  Closure(array<string, mixed>): array<string, mixed>  $validateLeaf
     * @return list<array<string, mixed>>
     */
    private function validateZone(array $elements, array $allowedTypes, string $zone, Closure $validateLeaf): array
    {
        return array_map(function (array $element) use ($allowedTypes, $zone, $validateLeaf): array {
            $validated = $validateLeaf($element);
            if (! in_array($validated['type'], $allowedTypes, true)) {
                throw ValidationException::withMessages([
                    "element.zones.{$zone}" => "Element type [{$validated['type']}] is not valid in the {$zone} zone.",
                ]);
            }

            return $validated;
        }, $elements);
    }

    /** @param list<array<string, mixed>> $elements */
    private function assertAtMostOneMediaCollection(array $elements): void
    {
        if (count(array_filter($elements, fn (array $element): bool => $element['type'] === 'mediaCollection')) > 1) {
            throw ValidationException::withMessages([
                'element' => 'A Composition Group may contain at most one Media Collection.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $element
     * @param  array<string, true>  $seen
     */
    private function collectId(array $element, array &$seen): void
    {
        $id = $element['id'];
        if (isset($seen[$id])) {
            throw ValidationException::withMessages([
                'elements' => "Element IDs must be unique within a section; duplicate [{$id}] found.",
            ]);
        }
        $seen[$id] = true;

        if (($element['type'] ?? null) === WebsiteElementType::MediaCollection->value) {
            foreach ($element['items'] as $item) {
                $this->collectId($item, $seen);
            }

            return;
        }

        if (($element['type'] ?? null) !== WebsiteElementType::CompositionGroup->value) {
            return;
        }

        $children = $element['composition'] === 'flow'
            ? $element['children']
            : [...$element['zones']['media'], ...$element['zones']['content']];

        foreach ($children as $child) {
            $this->collectId($child, $seen);
        }
    }

    /** @return list<string> */
    private function idRules(): array
    {
        return ['required', 'string', 'max:255', 'not_regex:/^\s*$/'];
    }
}
