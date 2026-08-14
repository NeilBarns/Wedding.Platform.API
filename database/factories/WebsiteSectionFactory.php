<?php

namespace Database\Factories;

use App\Models\Website;
use App\Models\WebsiteSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WebsiteSection> */
class WebsiteSectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'type' => fake()->randomElement(['hero', 'story', 'venue']),
            'sort_order' => fake()->numberBetween(1, 20),
            'is_enabled' => true,
            'content' => [],
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_enabled' => false,
        ]);
    }
}
