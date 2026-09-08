<?php

namespace App\Website\Elements;

use Illuminate\Support\Str;

final class WebsiteElementIdentityRegenerator
{
    /** @param array{elements: list<array<string, mixed>>, order: list<array<string, string>>} $flow @return array{elements: list<array<string, mixed>>, order: list<array<string, string>>} */
    public function regenerateFlow(array $flow): array
    {
        $ids = [];
        $elements = $this->regenerateWithMap($flow['elements'], $ids);
        $order = array_map(function (array $reference) use ($ids): array {
            return $reference['kind'] === 'element'
                ? ['kind' => 'element', 'id' => $ids[$reference['id']]]
                : $reference;
        }, $flow['order']);

        return compact('elements', 'order');
    }

    /** @param list<array<string, mixed>> $elements @return list<array<string, mixed>> */
    public function regenerate(array $elements): array
    {
        $ids = [];

        return $this->regenerateWithMap($elements, $ids);
    }

    /** @param list<array<string, mixed>> $elements @param array<string, string> $ids @return list<array<string, mixed>> */
    private function regenerateWithMap(array $elements, array &$ids): array
    {
        $regenerated = [];
        foreach ($elements as $element) {
            $regenerated[] = $this->element($element, $ids);
        }

        return $regenerated;
    }

    /** @param array<string, mixed> $element @param array<string, string> $ids @return array<string, mixed> */
    private function element(array $element, array &$ids): array
    {
        $previousId = $element['id'];
        $element['id'] = (string) Str::ulid();
        $ids[$previousId] = $element['id'];
        if (is_array($element['children'] ?? null)) {
            $element['children'] = $this->regenerateWithMap($element['children'], $ids);
        }
        if (is_array($element['items'] ?? null)) {
            $element['items'] = array_map(function (mixed $item): mixed {
                if (! is_array($item)) {
                    return $item;
                }
                $item['id'] = (string) Str::ulid();

                return $item;
            }, $element['items']);
        }

        return $element;
    }
}
