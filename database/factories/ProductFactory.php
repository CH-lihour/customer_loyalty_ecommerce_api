<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $price = fake()->numberBetween(5, 50);

        return [
            'category_id' => Category::factory(),
            'name' => fake()->unique()->words(3, true),
            'name_kh' => null,
            'sku' => fake()->unique()->bothify('PRD-####??'),
            'description' => fake()->sentence(),
            'price' => $price,
            'cost_price' => 0,
            'reorder_level' => 0,
            'normal_points' => $price,
            'bonus_multiplier' => 1,
            'is_featured' => false,
        ];
    }

    /**
     * Indicate that the product is featured on the storefront.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
        ]);
    }
}
