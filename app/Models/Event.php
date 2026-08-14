<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\EventType;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'type',
        'name',
        'slug',
        'event_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'event_date' => 'date',
            'status' => EventStatus::class,
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(EventMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_memberships')
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    public function website(): HasOne
    {
        return $this->hasOne(Website::class);
    }
}
