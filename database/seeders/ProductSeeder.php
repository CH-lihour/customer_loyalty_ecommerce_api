<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class ProductSeeder extends Seeder
{
    /**
     * Seed the demo catalog. Requires the CategorySeeder to have run first.
     */
    public function run(): void
    {
        $products = [
            ['sku' => 'PRD-0001', 'category' => 'Coffee & Tea', 'name' => 'Kampot Pepper Coffee', 'name_kh' => 'កាហ្វេម្រេចកំពត', 'price' => 12, 'bonus_multiplier' => 2, 'is_featured' => true, 'image' => 'photo-1514432324607-a09d9b4aefdd'],
            ['sku' => 'PRD-0002', 'category' => 'Snacks', 'name' => 'Khmer Snack Variety Box', 'name_kh' => 'ប្រអប់អាហារសម្ល', 'price' => 18, 'bonus_multiplier' => 1.5, 'is_featured' => true, 'image' => 'photo-1621939514649-280e2ee25f60'],
            ['sku' => 'PRD-0003', 'category' => 'Clothing', 'name' => 'Silk Krama Scarf', 'name_kh' => 'ក្រមាសូត្រ', 'price' => 35, 'bonus_multiplier' => 1, 'is_featured' => true, 'image' => 'photo-1606760227091-3dd870d97f1d'],
            ['sku' => 'PRD-0004', 'category' => 'Food & Grocery', 'name' => 'Jasmine Rice (5kg)', 'name_kh' => 'អង្ករ​ម្លិះ (5គីឡូ)', 'price' => 8, 'bonus_multiplier' => 1, 'is_featured' => false, 'image' => 'photo-1586201375761-83865001e31c'],
            ['sku' => 'PRD-0005', 'category' => 'Beauty', 'name' => 'Coconut Body Lotion', 'name_kh' => 'លីកូនខ្លាញ់ដូង', 'price' => 14, 'bonus_multiplier' => 2, 'is_featured' => true, 'image' => 'photo-1556228578-0d85b1a4d571'],
            ['sku' => 'PRD-0006', 'category' => 'Beverages', 'name' => 'Angkor Craft Beer 6-Pack', 'name_kh' => 'ស្រាបៀរអង្គរ (6ដប)', 'price' => 16, 'bonus_multiplier' => 1, 'is_featured' => false, 'image' => 'photo-1608270586620-248524c67de9'],
            ['sku' => 'PRD-0007', 'category' => 'Electronics', 'name' => 'Wireless Earbuds', 'name_kh' => 'កាស wireless', 'price' => 45, 'bonus_multiplier' => 1.5, 'is_featured' => true, 'image' => 'photo-1590658268037-6bf12165a8df'],
            ['sku' => 'PRD-0008', 'category' => 'Snacks', 'name' => 'Dried Mango Strips', 'name_kh' => 'ស្វាយស្ងួត', 'price' => 6, 'bonus_multiplier' => 3, 'is_featured' => false, 'image' => 'photo-1601493700631-2851384deff5'],
            ['sku' => 'PRD-0009', 'category' => 'Beauty', 'name' => 'Lemongrass Essential Oil', 'name_kh' => 'ប្រេងស្ករស', 'price' => 22, 'bonus_multiplier' => 2, 'is_featured' => false, 'image' => 'photo-1608571423902-eed4a5ad8108'],
            ['sku' => 'PRD-0010', 'category' => 'Coffee & Tea', 'name' => 'Cambodian Coffee Drip Bag Box', 'name_kh' => 'ថង់ទម្លាក់កាហ្វេ', 'price' => 20, 'bonus_multiplier' => 2, 'is_featured' => false, 'image' => 'photo-1611854779393-1b2da9d400fe'],
            ['sku' => 'PRD-0011', 'category' => 'Accessories', 'name' => 'Phone Stand (Bamboo)', 'name_kh' => 'ជំហររ​ទូរស័ព្ទ', 'price' => 9, 'bonus_multiplier' => 1, 'is_featured' => false, 'image' => 'photo-1586816879360-004f4d142167'],
            ['sku' => 'PRD-0012', 'category' => 'Accessories', 'name' => 'Traditional Khmer Pottery', 'name_kh' => 'ភាជន៍ប្រឡោះ', 'price' => 28, 'bonus_multiplier' => 1.5, 'is_featured' => false, 'image' => 'photo-1565193566173-7a0ee3dbe261'],
        ];

        $categoryIds = Category::withTrashed()->pluck('id', 'name');

        foreach ($products as $product) {
            $saved = Product::withTrashed()->updateOrCreate(['sku' => $product['sku']], [
                ...Arr::except($product, ['category', 'image']),
                'category_id' => $categoryIds[$product['category']],
                'normal_points' => $product['price'],
            ]);
            // Demo covers are external URLs; staff can replace them with uploads.
            $saved->images()->firstOrCreate(['sort_order' => 0], [
                'path' => "https://images.unsplash.com/{$product['image']}?w=800&h=600&fit=crop&auto=format",
            ]);
        }
    }
}
