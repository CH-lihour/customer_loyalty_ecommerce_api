<?php

namespace Database\Factories;

use App\Models\Reward;
use App\Models\RewardVariant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardVariant>
 */
class RewardVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reward_id' => Reward::factory(),
            'size' => 'Standard',
            'color' => fake()->safeColorName(),
            'sku' => 'VAR-'.Str::upper(Str::random(8)),
        ];
    }
}
