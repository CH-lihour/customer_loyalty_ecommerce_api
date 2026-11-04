<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
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
            'address_id' => fn (array $attributes) => Address::factory()->create(['customer_id' => $attributes['customer_id']])->id,
            'status' => Order::PENDING,
            'total_amount' => fake()->randomFloat(2, 5, 200),
            'ordered_at' => now(),
            'delivery_phone' => '012345678',
        ];
    }
}
