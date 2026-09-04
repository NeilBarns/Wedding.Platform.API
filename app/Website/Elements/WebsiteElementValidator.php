<?php

namespace App\Website\Elements;

use App\Website\Capabilities\PlatformFontRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WebsiteElementValidator
{
    private readonly PlatformFontRegistry $fonts;

    public function __construct(private readonly CompositionGroupValidator $groups, ?PlatformFontRegistry $fonts = null)
    {
        $this->fonts = $fonts ?? new PlatformFontRegistry;
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array<string, mixed>
     */
    public function validate(array $element): array
    {
        return $this->validateAtDepth($element, 0);
    }

    /** @param array<string,mixed> $element @return array<string,mixed> */
    private function validateAtDepth(array $element, int $depth): array
    {
        $type = $this->elementType($element);

        if ($type === WebsiteElementType::CompositionGroup) {
            if ($depth >= 2) {
                throw ValidationException::withMessages(['element.type' => 'Groups may be nested at most two levels deep.']);
            }

            return $this->groups->validate($element, fn (array $child): array => $this->validateAtDepth($child, $depth + 1));
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

        if ($type === WebsiteElementType::Divider && is_array($element['appearance'] ?? null)) {
            if (! isset($element['appearance']['assetId']) && is_string($element['appearance']['styleId'] ?? null)) {
                $element['appearance']['assetId'] = $element['appearance']['styleId'];
            }
            unset($element['appearance']['styleId']);
            if (is_string($element['appearance']['width'] ?? null)) {
                $legacyWidths = ['small' => 0, 'medium' => 50, 'large' => 100, 'full' => 100];
                $element['appearance']['width'] = $legacyWidths[$element['appearance']['width']] ?? $element['appearance']['width'];
            }
            if (is_string($element['appearance']['opacity'] ?? null) && ctype_digit($element['appearance']['opacity'])) {
                $element['appearance']['opacity'] = (int) $element['appearance']['opacity'];
            }
        }

        $rules = match ($type) {
            WebsiteElementType::Heading => $this->textRules('heading', 255),
            WebsiteElementType::Text => $this->textElementRules(),
            WebsiteElementType::RichText => $this->richTextElementRules(),
            WebsiteElementType::Image => $this->imageRules(),
            WebsiteElementType::Divider => $this->dividerRules(),
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
        if ($type === WebsiteElementType::Text) {
            $validated['text'] = $this->normalizeText($validated['text']);
            $this->assertTextFontTuple($validated);
        }
        if ($type === WebsiteElementType::RichText) {
            $validated['document'] = $this->normalizeRichTextDocument($validated['document']);
            $this->assertRichTextDocument($validated['document']);
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
    private function textElementRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,text,appearance'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:text'],
            'element.text' => ['present', 'string', 'max:5000'],
            'element.appearance' => ['sometimes', 'array:fontFamilyId,fontSize,fontWeight,lineHeight,letterSpacing,alignment,colorId,italic,underline,strikethrough,textTransform,responsive'],
            'element.appearance.fontFamilyId' => ['sometimes', 'string', 'min:1'],
            'element.appearance.fontSize' => ['sometimes', 'in:xs,s,m,l,xl'],
            'element.appearance.fontWeight' => ['sometimes', 'integer', 'in:400,600,700'],
            'element.appearance.lineHeight' => ['sometimes', 'in:tight,normal,relaxed'],
            'element.appearance.letterSpacing' => ['sometimes', 'in:tight,normal,wide'],
            'element.appearance.alignment' => ['sometimes', 'in:start,center,end'],
            'element.appearance.colorId' => ['sometimes', 'string', 'min:1'],
            'element.appearance.italic' => ['sometimes', 'boolean'],
            'element.appearance.underline' => ['sometimes', 'boolean'],
            'element.appearance.strikethrough' => ['sometimes', 'boolean'],
            'element.appearance.textTransform' => ['sometimes', 'in:none,uppercase,lowercase,capitalize'],
            'element.appearance.responsive' => ['sometimes', 'array:tablet,mobile'],
            'element.appearance.responsive.tablet' => ['sometimes', 'array:fontSize,alignment'],
            'element.appearance.responsive.tablet.fontSize' => ['sometimes', 'in:xs,s,m,l,xl'],
            'element.appearance.responsive.tablet.alignment' => ['sometimes', 'in:start,center,end'],
            'element.appearance.responsive.mobile' => ['sometimes', 'array:fontSize,alignment'],
            'element.appearance.responsive.mobile.fontSize' => ['sometimes', 'in:xs,s,m,l,xl'],
            'element.appearance.responsive.mobile.alignment' => ['sometimes', 'in:start,center,end'],
        ];
    }

    private function normalizeText(string $text): string
    {
        return preg_replace('/(?:\r\n|[\r\n\x{2028}\x{2029}])+/u', ' ', $text) ?? $text;
    }

    /** @return array<string, list<string>> */
    private function richTextElementRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,document,appearance'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:richText'],
            'element.document' => ['required', 'array:type,children'],
            'element.document.type' => ['required', 'in:doc'],
            'element.document.children' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'element.document.children.*' => ['required', 'array'],
            'element.appearance' => ['sometimes', 'array:fontFamilyId,fontSize,lineHeight,letterSpacing,alignment,colorId,textTransform,responsive'],
            'element.appearance.fontFamilyId' => ['sometimes', 'string', 'min:1'],
            'element.appearance.fontSize' => ['sometimes', 'in:xs,s,m,l,xl'],
            'element.appearance.lineHeight' => ['sometimes', 'in:tight,normal,relaxed'],
            'element.appearance.letterSpacing' => ['sometimes', 'in:tight,normal,wide'],
            'element.appearance.alignment' => ['sometimes', 'in:start,center,end'],
            'element.appearance.colorId' => ['sometimes', 'string', 'min:1'],
            'element.appearance.textTransform' => ['sometimes', 'in:none,uppercase,lowercase,capitalize'],
            'element.appearance.responsive' => ['sometimes', 'array:tablet,mobile'],
            'element.appearance.responsive.tablet' => ['sometimes', 'array:fontSize,alignment'],
            'element.appearance.responsive.tablet.fontSize' => ['sometimes', 'in:xs,s,m,l,xl'],
            'element.appearance.responsive.tablet.alignment' => ['sometimes', 'in:start,center,end'],
            'element.appearance.responsive.mobile' => ['sometimes', 'array:fontSize,alignment'],
            'element.appearance.responsive.mobile.fontSize' => ['sometimes', 'in:xs,s,m,l,xl'],
            'element.appearance.responsive.mobile.alignment' => ['sometimes', 'in:start,center,end'],
        ];
    }

    /** @return array<string, list<string>> */
    private function dividerRules(): array
    {
        return [
            'element' => ['required', 'array:id,type,appearance'],
            'element.id' => $this->idRules(),
            'element.type' => ['required', 'in:divider'],
            'element.appearance' => ['sometimes', 'array:assetId,width,alignment,colorId,opacity'],
            'element.appearance.assetId' => ['sometimes', 'string', 'min:1', 'max:100'],
            'element.appearance.width' => ['sometimes', 'integer', 'between:0,100'],
            'element.appearance.alignment' => ['sometimes', 'in:start,center,end'],
            'element.appearance.colorId' => ['sometimes', 'string', 'min:1'],
            'element.appearance.opacity' => ['sometimes', 'integer', 'between:25,100'],
        ];
    }

    /** @param array<string, mixed> $document */
    private function assertRichTextDocument(array $document): void
    {
        $length = 0;
        foreach ($document['children'] as $blockIndex => $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (! in_array($type, ['paragraph', 'bulletList', 'orderedList'], true)) {
                throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Invalid Rich Text block.']);
            }
            $expected = $type === 'paragraph' ? ['type', 'children'] : ['type', 'items'];
            if (array_diff(array_keys($block), $expected) !== [] || array_diff($expected, array_keys($block)) !== []) {
                throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Invalid Rich Text block structure.']);
            }
            $collections = $type === 'paragraph' ? [$block['children']] : $block['items'];
            if (! is_array($collections) || $collections === []) {
                throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Rich Text content is required.']);
            }
            foreach ($collections as $runs) {
                if (! is_array($runs) || $runs === []) {
                    throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Rich Text content is required.']);
                }
                foreach ($runs as $run) {
                    if (! is_array($run) || ! array_key_exists('text', $run) || ! is_string($run['text']) || array_diff(array_keys($run), ['text', 'marks']) !== []) {
                        throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Invalid Rich Text run.']);
                    }
                    $length += mb_strlen($run['text']);
                    if (isset($run['marks'])) {
                        $this->assertRichTextMarks($run['marks'], $blockIndex);
                    }
                }
            }
        }
        if ($length > 20000) {
            throw ValidationException::withMessages(['element.document' => 'Rich Text cannot exceed 20000 characters.']);
        }
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    private function normalizeRichTextDocument(array $document): array
    {
        $normalizeRun = static function (mixed $run): array {
            if (is_string($run)) {
                return ['text' => $run];
            }
            if (! is_array($run)) {
                return ['text' => ''];
            }
            $normalized = ['text' => is_string($run['text'] ?? null) ? $run['text'] : ''];
            if (isset($run['marks']) && is_array($run['marks'])) {
                $normalized['marks'] = array_intersect_key($run['marks'], array_flip(['bold', 'italic', 'underline', 'strikethrough', 'link']));
            }

            return $normalized;
        };

        $document['children'] = array_map(static function (mixed $block) use ($normalizeRun): mixed {
            if (! is_array($block)) {
                return $block;
            }
            if (($block['type'] ?? null) === 'paragraph' && is_array($block['children'] ?? null)) {
                $block['children'] = array_map($normalizeRun, $block['children']);
            }
            if (in_array(($block['type'] ?? null), ['bulletList', 'orderedList'], true) && is_array($block['items'] ?? null)) {
                $block['items'] = array_map(static fn (mixed $item): mixed => is_array($item) ? array_map($normalizeRun, $item) : $item, $block['items']);
            }

            return $block;
        }, $document['children'] ?? []);

        return $document;
    }

    private function assertRichTextMarks(mixed $marks, int $blockIndex): void
    {
        if (! is_array($marks) || array_diff(array_keys($marks), ['bold', 'italic', 'underline', 'strikethrough', 'link']) !== []) {
            throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Invalid Rich Text marks.']);
        }
        foreach (['bold', 'italic', 'underline', 'strikethrough'] as $mark) {
            if (isset($marks[$mark]) && ! is_bool($marks[$mark])) {
                throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Rich Text marks must be boolean.']);
            }
        }
        if (isset($marks['link']) && (! is_string($marks['link']) || strlen($marks['link']) > 2048 || filter_var($marks['link'], FILTER_VALIDATE_URL) === false || ! in_array(parse_url($marks['link'], PHP_URL_SCHEME), ['http', 'https', 'mailto'], true))) {
            throw ValidationException::withMessages(["element.document.children.{$blockIndex}" => 'Invalid Rich Text link.']);
        }
    }

    /** @param array<string, mixed> $element */
    private function assertTextFontTuple(array $element): void
    {
        $appearance = $element['appearance'] ?? [];
        $fontId = $appearance['fontFamilyId'] ?? null;
        if (! is_string($fontId)) {
            return;
        }
        $fonts = [...$this->fonts->platformFonts(), ...$this->fonts->classicLegacyFonts(), ...$this->fonts->modernLegacyFonts()];
        $font = collect($fonts)->first(fn ($candidate): bool => $candidate->id === $fontId);
        if ($font === null) {
            throw ValidationException::withMessages(['element.appearance.fontFamilyId' => 'The Text font family is not supported.']);
        }
        if (isset($appearance['fontWeight']) && ! in_array($appearance['fontWeight'], $font->weights, true)) {
            throw ValidationException::withMessages(['element.appearance.fontWeight' => 'The Text font weight is not supported by the selected family.']);
        }
        if (($appearance['italic'] ?? false) === true && ! in_array('italic', $font->styles, true)) {
            throw ValidationException::withMessages(['element.appearance.italic' => 'Italic is not supported by the selected family.']);
        }
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
