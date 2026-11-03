<?php

namespace Database\Factories;

use App\Models\Reward;
use App\Models\Tier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reward>
 */
class RewardFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'min_tier_id' => Tier::factory(),
            'name' => fake()->unique()->words(3, true),
            'name_kh' => null,
            'description' => fake()->sentence(),
            'category' => 'Discount',
            'points_cost' => fake()->numberBetween(100, 2000),
            'stock' => 0,
            'image' => null,
            'is_active' => true,
            'expires_at' => null,
        ];
    }

    /**
     * Indicate that the reward is hidden from customers.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
