<?php

namespace App\Http\Requests;

use App\Enums\EventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(EventType::class)],
            'eventDate' => ['nullable', 'date'],
            'slug' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function eventAttributes(): array
    {
        $validated = $this->validated();

        return array_filter([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'event_date' => $validated['eventDate'] ?? null,
            'slug' => $validated['slug'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
