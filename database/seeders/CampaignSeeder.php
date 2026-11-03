<?php

namespace Database\Seeders;

use App\Models\Campaign;
use Illuminate\Database\Seeder;

class CampaignSeeder extends Seeder
{
    /**
     * Seed sample campaigns whose banners show on the customer home page. The storefront had no demo campaigns.
     */
    public function run(): void
    {
        $campaigns = [
            [
                'name' => 'Double Points Week',
                'name_kh' => 'សប្តាហ៍ពិន្ទុទ្វេដង',
                'description' => 'Every completed order earns twice the loyalty points.',
                'image' => 'https://images.unsplash.com/photo-1607083206869-4c7672e72a8a?w=1200&h=400&fit=crop&auto=format',
                'banner_title' => 'Double points on every order',
                'banner_subtitle' => 'Shop this week and earn 2× loyalty points on everything.',
                'button_label' => 'Shop now',
                'link_url' => '/products',
                'multiplier' => 2,
                'sort_order' => 1,
            ],
            [
                'name' => 'Kampot Coffee Festival',
                'name_kh' => 'ពិធីបុណ្យកាហ្វេកំពត',
                'description' => 'Spotlight on Kampot coffee and pepper.',
                'image' => 'https://images.unsplash.com/photo-1447933601403-0c6688de566e?w=1200&h=400&fit=crop&auto=format',
                'banner_title' => 'Kampot Coffee Festival',
                'banner_subtitle' => 'Discover single-origin Cambodian coffee, roasted fresh.',
                'button_label' => 'Explore coffee',
                'link_url' => '/products',
                'multiplier' => 1,
                'sort_order' => 2,
            ],
            [
                'name' => 'Rewards Month',
                'name_kh' => 'ខែរង្វាន់',
                'description' => 'Promote the rewards catalog.',
                'image' => 'https://images.unsplash.com/photo-1513201099705-a9746e1e201f?w=1200&h=400&fit=crop&auto=format',
                'banner_title' => 'Turn your points into rewards',
                'banner_subtitle' => 'Vouchers, free products and exclusive gifts are waiting.',
                'button_label' => 'See rewards',
                'link_url' => '/rewards',
                'multiplier' => 1,
                'sort_order' => 3,
            ],
        ];

        foreach ($campaigns as $campaign) {
            Campaign::updateOrCreate(['name' => $campaign['name']], [
                ...$campaign,
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->addMonths(2)->endOfMonth()->toDateString(),
                'show_on_home' => true,
            ]);
        }
    }
}
