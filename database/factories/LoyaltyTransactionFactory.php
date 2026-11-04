<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyTransaction>
 */
class LoyaltyTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $points = fake()->numberBetween(10, 500);

        return [
            'customer_id' => Customer::factory(),
            'type' => LoyaltyTransaction::TYPE_EARN,
            'points' => $points,
            'balance_before' => 0,
            'balance_after' => $points,
            'reason' => fake()->sentence(3),
        ];
    }
}
