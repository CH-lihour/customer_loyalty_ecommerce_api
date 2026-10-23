<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Seed the storefront product categories.
     */
    public function run(): void
    {
        $categories = [
            ['name' => 'Coffee & Tea', 'name_kh' => 'កាហ្វេ និងតែ', 'description' => 'Local coffee beans, teas, and brewing goods.'],
            ['name' => 'Snacks', 'name_kh' => 'អាហារសម្រន់', 'description' => 'Dried fruit, chips, and treats.'],
            ['name' => 'Food & Grocery', 'name_kh' => 'អាហារ និងគ្រឿងទេស', 'description' => 'Pantry staples and Khmer specialties.'],
            ['name' => 'Clothing', 'name_kh' => 'សម្លៀកបំពាក់', 'description' => 'Krama, silk, and apparel.'],
            ['name' => 'Beauty', 'name_kh' => 'សម្ផស្ស', 'description' => 'Skincare and natural cosmetics.'],
            ['name' => 'Beverages', 'name_kh' => 'ភេសជ្ជៈ', 'description' => 'Ready-to-drink juices and drinks.'],
            ['name' => 'Electronics', 'name_kh' => 'គ្រឿងអេឡិចត្រូនិច', 'description' => 'Gadgets and accessories for devices.'],
            ['name' => 'Accessories', 'name_kh' => 'គ្រឿងតុបតែង', 'description' => 'Handicrafts, home decor, and gifts.'],
        ];

        foreach ($categories as $category) {
            Category::withTrashed()->updateOrCreate(['name' => $category['name']], $category);
        }
    }
}
