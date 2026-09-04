<?php

namespace App\Website\Elements;

use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CompositionGroupValidator
{
    /** @param array<string,mixed> $group @param Closure(array<string,mixed>):array<string,mixed> $validateChild @return array<string,mixed> */
    public function validate(array $group, Closure $validateChild): array
    {
        $validated = Validator::make(['element' => $group], [
            'element' => ['required', 'array:id,type,children,layout'], 'element.id' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'], 'element.type' => ['required', 'in:compositionGroup'],
            'element.children' => ['present', 'array', 'list', 'max:20'], 'element.children.*' => ['required', 'array'],
            'element.layout' => ['sometimes', 'array:width,direction,gap,padding,alignment,columns,responsive'], 'element.layout.width' => ['sometimes', 'in:full,wide,medium,narrow'], 'element.layout.direction' => ['sometimes', 'in:vertical,horizontal'], 'element.layout.gap' => ['sometimes', 'in:none,xs,s,m,l,xl'], 'element.layout.alignment' => ['sometimes', 'in:start,center,end,stretch'], 'element.layout.columns' => ['sometimes', 'in:equal-2,content-wide,content-narrow,equal-3'],
            'element.layout.padding' => ['sometimes', 'array:top,right,bottom,left'], 'element.layout.padding.*' => ['sometimes', 'in:none,xs,s,m,l,xl'],
            'element.layout.responsive' => ['sometimes', 'array:tablet,mobile'], 'element.layout.responsive.*' => ['sometimes', 'array:direction,gap,padding,alignment,columns'], 'element.layout.responsive.*.direction' => ['sometimes', 'in:vertical,horizontal'], 'element.layout.responsive.*.gap' => ['sometimes', 'in:none,xs,s,m,l,xl'], 'element.layout.responsive.*.alignment' => ['sometimes', 'in:start,center,end,stretch'], 'element.layout.responsive.*.columns' => ['sometimes', 'in:equal-2,content-wide,content-narrow,equal-3'], 'element.layout.responsive.*.padding' => ['sometimes', 'array:top,right,bottom,left'], 'element.layout.responsive.*.padding.*' => ['sometimes', 'in:none,xs,s,m,l,xl'],
        ])->validate()['element'];
        $validated['id'] = trim($validated['id']);
        $validated['children'] = array_map($validateChild, $validated['children']);
        $this->assertUniqueTreeIds([$validated]);

        return $validated;
    }

    /** @param list<array<string,mixed>> $elements */
    public function assertUniqueTreeIds(array $elements): void
    {
        $seen = [];
        $visit = function (array $element) use (&$seen, &$visit): void {
            $id = $element['id'];
            if (isset($seen[$id])) {
                throw ValidationException::withMessages(['elements' => "Element IDs must be unique within a section; duplicate [{$id}] found."]);
            }
            $seen[$id] = true;
            if (($element['type'] ?? null) === 'mediaCollection') {
                foreach ($element['items'] as $item) {
                    $visit($item);
                }
            }
            if (($element['type'] ?? null) === 'compositionGroup') {
                foreach ($element['children'] as $child) {
                    $visit($child);
                }
            }
        };
        foreach ($elements as $element) {
            $visit($element);
        }
    }
}
