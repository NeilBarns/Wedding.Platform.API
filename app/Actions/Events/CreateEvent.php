<?php

namespace App\Actions\Events;

use App\Actions\Websites\InitializeWebsiteSections;
use App\Enums\EventMembershipRole;
use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateEvent
{
    public function __construct(private readonly InitializeWebsiteSections $initializeWebsiteSections) {}

    /**
     * @param  array{name: string, type?: EventType|string, slug?: string, event_date?: mixed, status?: EventStatus|string}  $attributes
     */
    public function handle(User $creator, array $attributes): Event
    {
        return DB::transaction(function () use ($creator, $attributes): Event {
            $attributes['type'] ??= EventType::Wedding;
            $attributes['status'] ??= EventStatus::Active;
            $attributes['slug'] = $this->uniqueSlug($attributes['slug'] ?? $attributes['name']);

            $event = Event::query()->create($attributes);

            $event->memberships()->create([
                'user_id' => $creator->getKey(),
                'role' => EventMembershipRole::Owner,
            ]);

            $website = $event->website()->create();
            $this->initializeWebsiteSections->handle($website);

            return $event;
        });
    }

    private function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'event';
        $slug = $base;
        $suffix = 2;

        while (Event::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
