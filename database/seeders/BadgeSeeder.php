<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    /**
     * Seed the storefront's starting badges, matching the frontend's demo badges.
     */
    public function run(): void
    {
        $badges = [
            ['code' => 'FIRST_PURCHASE', 'name' => 'First Purchase', 'name_kh' => 'ការទិញលើកដំបូង', 'description' => 'Complete your first order', 'icon' => 'cart', 'color' => '#3b82f6', 'metric' => 'orders', 'threshold' => 1],
            ['code' => 'LOYAL_CUSTOMER', 'name' => 'Loyal Customer', 'name_kh' => 'អតិថិជនស្មោះ', 'description' => 'Active for 6+ months', 'icon' => 'heart', 'color' => '#f59e0b', 'metric' => 'membershipDays', 'threshold' => 180],
            ['code' => 'BIG_SPENDER', 'name' => 'Big Spender', 'name_kh' => 'ចំណាយច្រើន', 'description' => 'Spend over $500 total', 'icon' => 'gem', 'color' => '#8b5cf6', 'metric' => 'spend', 'threshold' => 500],
            ['code' => 'FIVE_ORDERS', 'name' => '5 Orders', 'name_kh' => 'ការបញ្ជាទិញ ៥', 'description' => 'Complete 5 orders', 'icon' => 'star', 'color' => '#10b981', 'metric' => 'orders', 'threshold' => 5],
            ['code' => 'TEN_ORDERS', 'name' => '10 Orders', 'name_kh' => 'ការបញ្ជាទិញ ១០', 'description' => 'Complete 10 orders', 'icon' => 'trophy', 'color' => '#f59e0b', 'metric' => 'orders', 'threshold' => 10],
            ['code' => 'REFERRAL_CHAMPION', 'name' => 'Referral Champion', 'name_kh' => 'ជើងឯកណែនាំ', 'description' => 'Successfully refer 5 friends', 'icon' => 'users', 'color' => '#ec4899', 'metric' => 'referrals', 'threshold' => 5],
            ['code' => 'POINT_COLLECTOR', 'name' => 'Point Collector', 'name_kh' => 'អ្នករក Point', 'description' => 'Earn 1,000+ loyalty points', 'icon' => 'star', 'color' => '#f59e0b', 'metric' => 'qualifyingPoints', 'threshold' => 1000],
        ];

        foreach ($badges as $badge) {
            Badge::updateOrCreate(['code' => $badge['code']], $badge);
        }
    }
}
