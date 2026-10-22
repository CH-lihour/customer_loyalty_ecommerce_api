<?php

namespace Database\Seeders;

use App\Models\Tier;
use Illuminate\Database\Seeder;

class TierSeeder extends Seeder
{
    /**
     * Seed the loyalty tiers, from the entry tier upwards.
     */
    public function run(): void
    {
        $tiers = [
            ['name' => 'Bronze', 'min_points' => 0, 'max_points' => 999, 'multiplier' => 1.00, 'color' => '#CD7F32'],
            ['name' => 'Silver', 'min_points' => 1000, 'max_points' => 4999, 'multiplier' => 1.25, 'color' => '#C0C0C0'],
            ['name' => 'Gold', 'min_points' => 5000, 'max_points' => 9999, 'multiplier' => 1.50, 'color' => '#FFD700'],
            ['name' => 'Platinum', 'min_points' => 10000, 'max_points' => null, 'multiplier' => 2.00, 'color' => '#E5E4E2'],
        ];

        foreach ($tiers as $tier) {
            Tier::updateOrCreate(['name' => $tier['name']], $tier);
        }
    }
}
