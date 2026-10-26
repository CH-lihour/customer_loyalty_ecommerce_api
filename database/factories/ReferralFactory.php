<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Referral;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Referral>
 */
class ReferralFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'referrer_id' => Customer::factory(),
            'referred_id' => Customer::factory(),
            'is_completed' => false,
        ];
    }
}
