<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Website> */
class WebsiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
        ];
    }
}
