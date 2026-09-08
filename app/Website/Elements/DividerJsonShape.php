<?php

namespace App\Website\Elements;

final class DividerJsonShape
{
    /** Preserve empty Divider objects when encoding PHP's associative JSON data.
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    public static function serializeElements(array $elements): array
    {
        return array_map(function (array $element): array {
            if (($element['type'] ?? null) === 'divider' && ($element['appearance'] ?? null) === []) {
                $element['appearance'] = new \stdClass;
            }
            if (($element['type'] ?? null) === 'compositionGroup') {
                $element['children'] = self::serializeElements($element['children']);
            }

            return $element;
        }, $elements);
    }
}
