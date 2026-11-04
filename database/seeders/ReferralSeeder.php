<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Referral;
use Illuminate\Database\Seeder;

class ReferralSeeder extends Seeder
{
    /**
     * Seed open referrals made by the demo customer. Runs once.
     */
    public function run(): void
    {
        $referrer = Customer::query()->where('email', 'customer@example.com')->first();

        if (! $referrer || Referral::query()->exists()) {
            return;
        }

        Customer::query()->whereKeyNot($referrer->id)->orderBy('id')->take(3)->pluck('id')
            ->each(fn (int $customerId) => Referral::query()->create(['referrer_id' => $referrer->id, 'referred_id' => $customerId]));
    }
}
