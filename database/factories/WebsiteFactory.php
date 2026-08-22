<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Website;
use App\Website\WebsiteTemplateRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Website> */
class WebsiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => Website::DEFAULT_NAME,
            'template_key' => WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1,
            'design_settings' => app(WebsiteTemplateRegistry::class)
                ->get(WebsiteTemplateRegistry::CLASSIC_FILIPINIANA_V1)
                ->defaultDesignSettings,
        ];
    }
}
