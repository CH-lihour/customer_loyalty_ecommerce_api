<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'multiplier' => 2,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'is_active' => true,
            'show_on_home' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the campaign shows as a banner on the customer home page.
     */
    public function banner(): static
    {
        return $this->state(fn (array $attributes) => [
            'show_on_home' => true,
            'banner_title' => fake()->sentence(3),
            'banner_subtitle' => fake()->sentence(),
            'image' => 'https://example.com/banner.jpg',
        ]);
    }

    /**
     * Indicate that the campaign is switched off.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
