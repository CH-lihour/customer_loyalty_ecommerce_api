<?php

namespace Database\Factories;

use App\Models\Reward;
use App\Models\RewardStockItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RewardStockItem>
 */
class RewardStockItemFactory extends Factory
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
            'supplier_id' => null,
            'sku' => 'RW-'.Str::upper(Str::random(8)),
            'unit_cost' => 2.5,
            'tracking' => 'none',
            'low_stock_threshold' => 5,
            'reorder_point' => 10,
            'reorder_qty' => 25,
            'out_of_stock' => 'disable',
            'allow_backorder' => false,
        ];
    }

    /**
     * Indicate that units are tracked by batch with an expiry date.
     */
    public function batchTracked(): static
    {
        return $this->state(fn (array $attributes) => [
            'tracking' => 'batch',
        ]);
    }

    /**
     * Indicate that each unit has a serial number.
     */
    public function serialTracked(): static
    {
        return $this->state(fn (array $attributes) => [
            'tracking' => 'serial',
        ]);
    }
}
