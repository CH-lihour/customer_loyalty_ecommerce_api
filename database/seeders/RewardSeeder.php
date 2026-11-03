<?php

namespace Database\Seeders;

use App\Models\Reward;
use App\Models\Tier;
use Illuminate\Database\Seeder;

class RewardSeeder extends Seeder
{
    /**
     * Seed the storefront's starting rewards, matching the frontend's demo rewards. Runs after the tiers.
     */
    public function run(): void
    {
        $tierIds = Tier::pluck('id', 'name');
        $entryTierId = Tier::orderBy('min_points')->value('id');
        $voucher = 'https://images.unsplash.com/photo-1607082349566-187342175e2f?w=300&h=200&fit=crop&auto=format';

        $rewards = [
            ['name' => '$2 Discount Voucher', 'name_kh' => 'គូប៉ុង $2', 'description' => 'Get $2 off your next order. Valid for 30 days.', 'points_cost' => 200, 'stock' => 999, 'tier' => 'Silver', 'category' => 'Discount', 'image' => $voucher],
            ['name' => '$5 Discount Voucher', 'name_kh' => 'គូប៉ុង $5', 'description' => 'Get $5 off your next order. Valid for 30 days.', 'points_cost' => 500, 'stock' => 500, 'tier' => 'Gold', 'category' => 'Discount', 'image' => $voucher],
            ['name' => 'Free Kampot Coffee', 'name_kh' => 'កាហ្វេកំពតឥតគិតថ្លៃ', 'description' => 'Redeem a free bag of Kampot Pepper Coffee. Delivered with your next order.', 'points_cost' => 400, 'stock' => 50, 'tier' => 'Silver', 'category' => 'Free Product', 'image' => 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd?w=300&h=200&fit=crop&auto=format'],
            ['name' => 'Free Shipping Voucher', 'name_kh' => 'ដឹកជញ្ជូនឥតគិតថ្លៃ', 'description' => 'Free shipping on your next order, any amount. Valid 14 days.', 'points_cost' => 150, 'stock' => 999, 'tier' => 'Silver', 'category' => 'Shipping', 'image' => 'https://images.unsplash.com/photo-1566576912321-d58ddd7a6088?w=300&h=200&fit=crop&auto=format'],
            ['name' => 'Khmer Snack Box', 'name_kh' => 'ប្រអប់អាហារ', 'description' => 'A premium selection of the best Cambodian snacks. Surprise box!', 'points_cost' => 800, 'stock' => 30, 'tier' => 'Gold', 'category' => 'Free Product', 'image' => 'https://images.unsplash.com/photo-1621939514649-280e2ee25f60?w=300&h=200&fit=crop&auto=format'],
            ['name' => '$15 Platinum Voucher', 'name_kh' => 'គូប៉ុង Platinum $15', 'description' => 'Exclusive $15 voucher for Platinum members only.', 'points_cost' => 1200, 'stock' => 100, 'tier' => 'Platinum', 'category' => 'Discount', 'image' => $voucher],
            ['name' => 'Silk Krama Gift', 'name_kh' => 'ក្រមាសូត្រជាអំណោយ', 'description' => 'A beautiful traditional Silk Krama — the iconic Cambodian garment. Platinum exclusive.', 'points_cost' => 2000, 'stock' => 15, 'tier' => 'Platinum', 'category' => 'Free Product', 'image' => 'https://images.unsplash.com/photo-1606760227091-3dd870d97f1d?w=300&h=200&fit=crop&auto=format'],
            ['name' => 'Coffee Tasting Kit', 'name_kh' => 'ឧបករណ៍សាកល្បងកាហ្វេ', 'description' => '5 varieties of Cambodian specialty coffee in one curated tasting kit.', 'points_cost' => 600, 'stock' => 20, 'tier' => 'Gold', 'category' => 'Free Product', 'image' => 'https://images.unsplash.com/photo-1611854779393-1b2da9d400fe?w=300&h=200&fit=crop&auto=format'],
        ];

        foreach ($rewards as $reward) {
            // A reward whose tier no longer exists falls back to the entry tier.
            $minTierId = $tierIds[$reward['tier']] ?? $entryTierId;
            unset($reward['tier']);

            Reward::updateOrCreate(['name' => $reward['name']], [...$reward, 'min_tier_id' => $minTierId]);
        }
    }
}
