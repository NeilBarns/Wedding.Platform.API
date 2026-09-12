<?php

namespace App\Website;

use Illuminate\Validation\ValidationException;

final class BackgroundMedia
{
    /** @return array<string, list<string>> */
    public static function rules(string $prefix): array
    {
        $rules = [
            $prefix => ['sometimes', 'nullable', 'array:assetId,focalPoint,zoom,responsive'],
            "{$prefix}.assetId" => ["required_with:{$prefix}", 'string', 'ulid'],
            "{$prefix}.focalPoint" => ['sometimes', 'array:x,y'],
            "{$prefix}.focalPoint.x" => ["required_with:{$prefix}.focalPoint", 'numeric', 'between:0,1'],
            "{$prefix}.focalPoint.y" => ["required_with:{$prefix}.focalPoint", 'numeric', 'between:0,1'],
            "{$prefix}.zoom" => ['sometimes', 'numeric', 'gt:0', 'lte:3'],
            "{$prefix}.responsive" => ['sometimes', 'array:tablet,mobile'],
        ];
        foreach (['tablet', 'mobile'] as $device) {
            $devicePrefix = "{$prefix}.responsive.{$device}";
            $rules[$devicePrefix] = ['sometimes', 'array:assetId,focalPoint,zoom'];
            $rules["{$devicePrefix}.assetId"] = ['sometimes', 'string', 'ulid'];
            $rules["{$devicePrefix}.focalPoint"] = ['sometimes', 'array:x,y'];
            $rules["{$devicePrefix}.focalPoint.x"] = ["required_with:{$devicePrefix}.focalPoint", 'numeric', 'between:0,1'];
            $rules["{$devicePrefix}.focalPoint.y"] = ["required_with:{$devicePrefix}.focalPoint", 'numeric', 'between:0,1'];
            $rules["{$devicePrefix}.zoom"] = ['sometimes', 'numeric', 'gt:0', 'lte:3'];
        }

        return $rules;
    }

    public static function assertJsonNumbers(mixed $media, string $path): void
    {
        if (! is_array($media)) {
            return;
        }
        foreach ([null, 'tablet', 'mobile'] as $device) {
            $framing = $device === null ? $media : ($media['responsive'][$device] ?? null);
            if (! is_array($framing)) {
                continue;
            }
            $fieldPath = $device === null ? $path : "{$path}.responsive.{$device}";
            if (array_key_exists('zoom', $framing) && ! is_int($framing['zoom']) && ! is_float($framing['zoom'])) {
                throw ValidationException::withMessages(["{$fieldPath}.zoom" => 'Background image zoom must be a JSON number.']);
            }
            foreach (['x', 'y'] as $coordinate) {
                $value = $framing['focalPoint'][$coordinate] ?? null;
                if ($value !== null && ! is_int($value) && ! is_float($value)) {
                    throw ValidationException::withMessages(["{$fieldPath}.focalPoint.{$coordinate}" => 'Background image focal point must be a JSON number.']);
                }
            }
        }
    }

    /** @param array<string, mixed> $media @return array<string, mixed> */
    public static function normalize(array $media): array
    {
        foreach (['tablet', 'mobile'] as $device) {
            if (! isset($media['responsive'][$device]) || ! is_array($media['responsive'][$device])) {
                continue;
            }
            if ($media['responsive'][$device] === []) {
                unset($media['responsive'][$device]);
            }
        }
        if (($media['responsive'] ?? null) === []) {
            unset($media['responsive']);
        }

        return $media;
    }

    /** @return list<string> */
    public static function assetIds(mixed $media): array
    {
        if (! is_array($media)) {
            return [];
        }
        $ids = [];
        foreach ([$media['assetId'] ?? null, $media['responsive']['tablet']['assetId'] ?? null, $media['responsive']['mobile']['assetId'] ?? null] as $id) {
            if (is_string($id) && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
