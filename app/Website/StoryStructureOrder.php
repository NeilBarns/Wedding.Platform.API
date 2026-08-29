<?php

namespace App\Website;

final class StoryStructureOrder
{
    /**
     * @param  list<mixed>  $order
     * @param  list<string>  $elementIds
     */
    public static function isCanonical(array $order, array $elementIds): bool
    {
        $singletons = ['story:eyebrow', 'story:heading', 'story:intro'];
        if (count($order) !== count($elementIds) + count($singletons) || count($order) > 23) {
            return false;
        }
        if (count(array_unique($order, SORT_REGULAR)) !== count($order)) {
            return false;
        }
        foreach ($singletons as $singleton) {
            if (! in_array($singleton, $order, true)) {
                return false;
            }
        }
        $projected = [];
        foreach ($order as $reference) {
            if (! is_string($reference)) {
                return false;
            }
            if (in_array($reference, $singletons, true)) {
                continue;
            }
            if (! str_starts_with($reference, 'narrative:') || $reference === 'narrative:') {
                return false;
            }
            $projected[] = substr($reference, strlen('narrative:'));
        }

        return $projected === $elementIds;
    }
}
