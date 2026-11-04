<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Redemption;
use App\Models\Reward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Redemption>
 */
class RedemptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'reward_id' => Reward::factory(),
            'points' => 100,
            'status' => Redemption::BACKORDERED,
            'fulfillment' => Redemption::NOT_FULFILLED,
        ];
    }
}
