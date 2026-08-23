<?php

namespace App\Website\Elements;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsiteElementValidator
{
    public function __construct(private readonly CompositionGroupValidator $groups) {}

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    public function validate(array $element): array
    {
        $type = $this->elementType($element);

        if ($type === WebsiteElementType::CompositionGroup) {
            return $this->groups->validate($element, fn (array $leaf): array => $this->validateLeaf($leaf));
        }

        return $this->validateLeaf($element);
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    public function validateTree(array $elements): array
    {
        $validated = array_map(fn (array $element): array => $this->validate($element), $elements);
        $this->groups->assertUniqueTreeIds($validated);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    private function validateLeaf(array $element): array
    {
        $type = $this->elementType($element);
        if ($type === WebsiteElementType::CompositionGroup) {
            throw ValidationException::withMessages([
                'element.type' => 'Composition Groups cannot be nested.',
            ]);
        }

        $rules = match ($type) {
            WebsiteElementType::Heading => $this->textRules('heading', 255),
            WebsiteElementType::Text => $this->textRules('text', 5000),
            WebsiteElementType::Image => $this->imageRules(),
            WebsiteElementType::Divider => $this->baseRules('divider'),
            WebsiteElementType::Quote => $this->quoteRules(),
            WebsiteElementType::Cta => $this->ctaRules($element),
            WebsiteElementType::MediaCollection => $this->mediaCollectionRules(),
            WebsiteElementType::NarrativeBlock => $this->narrativeBlockRules(),
            WebsiteElementType::EventDate => $this->baseRules('eventDate'),
            WebsiteElementType::EventTime => $this->baseRules('eventTime'),
            WebsiteElementType::Countdown => $this->baseRules('countdown'),
            WebsiteElementType::CompositionGroup => throw new \LogicException('Composition Groups are validated separately.'),
        };

        $validated = Validator::make(['element' => $element], $rules)->validate()['element'];
        $validated['id'] = trim($validated['id']);

        if ($type === WebsiteElementType::Cta && isset($validated['action']['sectionId'])) {
            $validated['action']['sectionId'] = trim($validated['action']['sectionId']);
        }
        if ($type === WebsiteElementType::MediaCollection) {
            $validated['items'] = array_map(function (array $item): array {
                $item['id'] = trim($item['id']);

                return $item;
            }, $validated['items']);
        }

        return $validated;
    }

    /** @param array<string, mixed> $element */
    private function elementType(array $element): WebsiteElementType
    {
        $value = $element['type'] ?? null;
        $type = is_string($value) ? WebsiteElementType::tryFrom($value) : null;

        if ($type === null) {
            throw ValidationException::withMessages([
                'element.type' => $value === null ? 'The element type is required.' : 'The element type is not supported.',
            ]);
        }

        return $type;
    }

    /** @return array<string, list<string>> */
    private function baseRules(string $type): array
    {
        return [
            'element' => ['required', 'array:id,type'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', "in:{$type}"],
        ];
    }

    /** @return array<string, list<string>> */
    private function textRules(string $type, int $maximum): array
    {
        return [
            'element' => ['required', 'array:id,type,text'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', "in:{$type}"],
            'element.text' => ['present', 'string', "max:{$maximum}"],
        ];
    }

    /** @return array<string, list<string>> */
    private function imageRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,mediaId'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:image'],
            'element.mediaId' => ['required', 'string', 'ulid'],
        ];
    }

    /** @return array<string, list<string>> */
    private function quoteRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,text,attribution'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:quote'],
            'element.text' => ['present', 'string', 'max:5000'],
            'element.attribution' => ['sometimes', 'string', 'max:255'],
        ];
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, list<string>>
     */
    private function ctaRules(array $element): array
    {
        $actionValue = $element['action']['type'] ?? null;
        $action = is_string($actionValue) ? CtaActionType::tryFrom($actionValue) : null;
        if ($action === null) {
            throw ValidationException::withMessages([
                'element.action.type' => $actionValue === null ? 'The CTA action type is required.' : 'The CTA action type is not supported.',
            ]);
        }

        $actionKeys = match ($action) {
            CtaActionType::ScrollToSection => 'type,sectionId',
            CtaActionType::ExternalUrl => 'type,url',
            default => 'type',
        };

        $rules = [
            'element' => ['required', 'array:id,type,label,action'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:cta'],
            'element.label' => ['present', 'string', 'max:255'],
            'element.action' => ['required', "array:{$actionKeys}"],
            'element.action.type' => ['required', 'in:'.$action->value],
        ];

        if ($action === CtaActionType::ScrollToSection) {
            $rules['element.action.sectionId'] = $this->idRules();
        }
        if ($action === CtaActionType::ExternalUrl) {
            $rules['element.action.url'] = ['required', 'string', 'max:2048', 'url:https'];
        }

        return $rules;
    }

    /** @return array<string, list<string>> */
    private function mediaCollectionRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,items'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:mediaCollection'],
            'element.items' => ['present', 'array', 'list'],
            'element.items.*' => ['required', 'array:id,mediaId'],
            'element.items.*.id' => $this->idRules(),
            'element.items.*.mediaId' => ['required', 'string', 'ulid'],
        ];
    }

    /** @return array<string, list<string>> */
    private function narrativeBlockRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,heading,body,media'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:narrativeBlock'],
            'element.heading' => ['sometimes', 'string', 'max:255'],
            'element.body' => ['present', 'string', 'max:10000'],
            'element.media' => ['sometimes', 'array:type,mediaId', 'required_array_keys:type,mediaId'],
            'element.media.type' => ['required_with:element.media', 'in:image'],
            'element.media.mediaId' => ['required_with:element.media', 'string', 'ulid'],
        ];
    }

    /** @return list<string> */
    private function idRules(): array
    {
        return ['required', 'string', 'max:255', 'not_regex:/^\s*$/'];
    }
}
