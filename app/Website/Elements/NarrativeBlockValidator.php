<?php

namespace App\Website\Elements;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class NarrativeBlockValidator
{
    /** @param array<string, mixed> $element */
    public function validate(array $element, array $allowedFontIdsByRole = []): array
    {
        // Laravel's global ConvertEmptyStringsToNull middleware runs before API
        // validation; restore explicit empty slot text because empty is semantic.
        foreach (['eyebrow', 'heading', 'body', 'quote', 'caption'] as $slot) {
            if (array_key_exists('text', $element['slots'][$slot] ?? []) && $element['slots'][$slot]['text'] === null) {
                $element['slots'][$slot]['text'] = '';
            }
        }
        if (array_key_exists('label', $element['slots']['cta'] ?? []) && $element['slots']['cta']['label'] === null) {
            $element['slots']['cta']['label'] = '';
        }

        $rules = [
            'element' => ['required', 'array:id,type,isHidden,slots,composition'],
            'element.id' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
            'element.type' => ['required', 'in:narrativeBlock'],
            'element.isHidden' => ['required', 'boolean'],
            'element.composition' => ['required', 'array:presentation,mediaPlacement,mediaTreatment,textAlignment,surface'],
            'element.composition.presentation' => ['required', 'in:editorial,mediaFirst,quoteLed,textOnly'],
            'element.composition.mediaPlacement' => ['sometimes', 'in:leading,trailing,above,below,splitStart,splitEnd,inset'],
            'element.composition.mediaTreatment' => ['sometimes', 'in:standard,wide,cinematic,fullBleed'],
            'element.composition.textAlignment' => ['sometimes', 'in:start,center,end'],
            'element.composition.surface' => ['sometimes', 'in:none,soft,feature'],
            'element.slots' => ['required', 'array:eyebrow,heading,divider,body,quote,media,caption,cta', 'required_array_keys:eyebrow,heading,divider,body,quote,media,caption,cta'],
            'element.slots.divider' => ['required', 'array:isHidden'],
            'element.slots.divider.isHidden' => ['required', 'boolean'],
            'element.slots.media' => ['required', 'array:isHidden,content'],
            'element.slots.media.isHidden' => ['required', 'boolean'],
            'element.slots.media.content' => ['present', 'nullable', 'array'],
            'element.slots.cta' => ['required', 'array:isHidden,label,action,appearance'],
            'element.slots.cta.isHidden' => ['required', 'boolean'],
            'element.slots.cta.label' => ['present', 'string', 'max:255'],
            'element.slots.cta.action' => ['present', 'nullable', 'array'],
            'element.slots.cta.appearance' => ['sometimes', 'array:fontFamilyId,fontSize,lineSpacing,letterSpacing,colorId'],
            'element.slots.cta.appearance.fontFamilyId' => ['sometimes', 'string', 'max:255'],
            'element.slots.cta.appearance.lineSpacing' => ['sometimes', 'in:tight,normal,relaxed'],
            'element.slots.cta.appearance.letterSpacing' => ['sometimes', 'in:tight,normal,wide'],
            'element.slots.cta.appearance.colorId' => ['sometimes', 'string', 'max:255'],
            'element.slots.cta.appearance.fontSize' => ['sometimes', 'array:desktop,tablet,mobile'],
            'element.slots.cta.appearance.fontSize.desktop' => ['sometimes', 'in:xs,s,m,l,xl'],
            'element.slots.cta.appearance.fontSize.tablet' => ['sometimes', 'in:xs,s,m,l,xl'],
            'element.slots.cta.appearance.fontSize.mobile' => ['sometimes', 'in:xs,s,m,l,xl'],
        ];

        foreach (['eyebrow' => 255, 'heading' => 255, 'body' => 10000, 'caption' => 1000] as $slot => $maximum) {
            $rules += $this->textSlotRules("element.slots.{$slot}", $maximum);
        }
        $rules += $this->textSlotRules('element.slots.quote', 5000, true);

        $validated = Validator::make(['element' => $element], $rules)->validate()['element'];
        $validated['id'] = trim($validated['id']);
        foreach (['eyebrow' => 'body', 'heading' => 'heading', 'body' => 'body', 'quote' => 'body', 'caption' => 'body', 'cta' => 'body'] as $slot => $role) {
            $fontId = $validated['slots'][$slot]['appearance']['fontFamilyId'] ?? null;
            if (is_string($fontId) && $allowedFontIdsByRole !== [] && ! in_array($fontId, $allowedFontIdsByRole[$role] ?? [], true)) {
                throw ValidationException::withMessages(["element.slots.{$slot}.appearance.fontFamilyId" => 'The selected font is not supported for this role.']);
            }
        }
        $this->validateMedia($validated['slots']['media']['content']);
        $this->validateAction($validated['slots']['cta']['action']);

        return $validated;
    }

    /** @return array<string, list<string>> */
    private function textSlotRules(string $path, int $maximum, bool $quote = false): array
    {
        $keys = $quote ? 'isHidden,text,attribution,appearance' : 'isHidden,text,appearance';
        $rules = [
            $path => ['required', "array:{$keys}"],
            "{$path}.isHidden" => ['required', 'boolean'],
            "{$path}.text" => ['present', 'string', "max:{$maximum}"],
            "{$path}.appearance" => ['sometimes', 'array:fontFamilyId,fontSize,lineSpacing,letterSpacing,colorId'],
            "{$path}.appearance.fontFamilyId" => ['sometimes', 'string', 'max:255'],
            "{$path}.appearance.lineSpacing" => ['sometimes', 'in:tight,normal,relaxed'],
            "{$path}.appearance.letterSpacing" => ['sometimes', 'in:tight,normal,wide'],
            "{$path}.appearance.colorId" => ['sometimes', 'string', 'max:255'],
            "{$path}.appearance.fontSize" => ['sometimes', 'array:desktop,tablet,mobile'],
            "{$path}.appearance.fontSize.desktop" => ['sometimes', 'in:xs,s,m,l,xl'],
            "{$path}.appearance.fontSize.tablet" => ['sometimes', 'in:xs,s,m,l,xl'],
            "{$path}.appearance.fontSize.mobile" => ['sometimes', 'in:xs,s,m,l,xl'],
        ];
        if ($quote) {
            $rules["{$path}.attribution"] = ['sometimes', 'string', 'max:255'];
        }

        return $rules;
    }

    /** @param array<string, mixed>|null $media */
    private function validateMedia(?array $media): void
    {
        if ($media === null) {
            return;
        }
        $type = $media['type'] ?? null;
        $rules = match ($type) {
            'image', 'video' => [
                'media' => ['required', 'array:type,mediaId'],
                'media.type' => ['required', "in:{$type}"],
                'media.mediaId' => ['required', 'string', 'ulid'],
            ],
            'mediaCollection' => [
                'media' => ['required', 'array:type,presentation,items'],
                'media.type' => ['required', 'in:mediaCollection'],
                'media.presentation' => ['required', 'in:grid,masonry,carousel,editorial,filmstrip'],
                'media.items' => ['present', 'array', 'list'],
                'media.items.*' => ['required', 'array:id,mediaId'],
                'media.items.*.id' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
                'media.items.*.mediaId' => ['required', 'string', 'ulid'],
            ],
            default => throw ValidationException::withMessages(['element.slots.media.content.type' => 'The Narrative Media type is not supported.']),
        };
        Validator::make(['media' => $media], $rules)->validate();
    }

    /** @param array<string, mixed>|null $action */
    private function validateAction(?array $action): void
    {
        if ($action === null) {
            return;
        }
        $type = is_string($action['type'] ?? null) ? CtaActionType::tryFrom($action['type']) : null;
        if ($type === null) {
            throw ValidationException::withMessages(['element.slots.cta.action.type' => 'The CTA action type is not supported.']);
        }
        $keys = match ($type) {
            CtaActionType::ScrollToSection => 'type,sectionId',
            CtaActionType::ExternalUrl => 'type,url',
            default => 'type',
        };
        $rules = [
            'action' => ['required', "array:{$keys}"],
            'action.type' => ['required', 'in:'.$type->value],
        ];
        if ($type === CtaActionType::ScrollToSection) {
            $rules['action.sectionId'] = ['required', 'string', 'max:255', 'not_regex:/^\s*$/'];
        }
        if ($type === CtaActionType::ExternalUrl) {
            $rules['action.url'] = ['required', 'string', 'max:2048', 'url:https'];
        }
        Validator::make(['action' => $action], $rules)->validate();
    }
}
