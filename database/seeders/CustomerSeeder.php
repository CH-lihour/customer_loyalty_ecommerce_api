<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Tier;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /**
     * Seed a known demo customer plus random customers spread across the existing tiers.
     */
    public function run(): void
    {
        $tiers = Tier::orderBy('min_points')->get();

        Customer::updateOrCreate(['email' => 'customer@example.com'], [
            'tier_id' => $tiers->first()->id,
            'name' => 'Test Customer',
            'phone' => '012345678',
            'password' => 'password',
            'referral_code' => 'TESTCUST',
        ]);

        Customer::factory(10)->recycle($tiers)->create();
    }
}
