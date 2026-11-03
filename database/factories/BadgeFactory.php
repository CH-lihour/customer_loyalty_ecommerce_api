<?php

namespace Database\Factories;

use App\Models\Badge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Badge>
 */
class BadgeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => Str::upper(fake()->unique()->lexify('BADGE_????')),
            'name' => fake()->words(2, true),
            'name_kh' => null,
            'description' => fake()->sentence(),
            'icon' => 'medal',
            'color' => fake()->hexColor(),
            'metric' => 'orders',
            'threshold' => 1,
        ];
    }
}
