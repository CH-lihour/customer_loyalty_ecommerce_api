<?php

namespace Database\Seeders;

use App\Models\Reward;
use App\Models\RewardStockMovement;
use App\Models\Role;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class StockSeeder extends Seeder
{
    /**
     * Seed the frontend's demo rewards with their stock rules, locations, suppliers and opening balances.
     * Opening balances are only added while the ledger is empty, so the seeder can run again safely.
     */
    public function run(): void
    {
        $locations = collect([
            ['name' => 'Phnom Penh Central Warehouse', 'name_kh' => 'ឃ្លាំងកណ្ដាលភ្នំពេញ', 'type' => 'warehouse'],
            ['name' => 'Siem Reap Store', 'name_kh' => 'ហាងសៀមរាប', 'type' => 'store'],
            ['name' => 'Battambang Pickup Point', 'name_kh' => 'ចំណុចទទួលបាត់ដំបង', 'type' => 'pickup'],
        ])->map(fn (array $location) => StockLocation::firstOrCreate(['name' => $location['name']], $location))->values();

        $suppliers = collect([
            'print' => ['name' => 'Mekong Print & Voucher', 'kind' => 'supplier', 'contact_name' => 'Rotha Prak', 'phone' => '+855 23 880 120', 'email' => 'orders@mekongprint.example'],
            'kampot' => ['name' => 'Kampot Pepper Coffee Co.', 'kind' => 'supplier', 'contact_name' => 'Sreypov Nhem', 'phone' => '+855 33 932 410', 'email' => 'sales@kampotcoffee.example'],
            'snack' => ['name' => 'Phsar Thmei Snack Traders', 'kind' => 'supplier', 'contact_name' => 'Heng Lay', 'phone' => '+855 12 448 902', 'email' => 'heng@snacktraders.example'],
            'krama' => ['name' => 'Takeo Silk Weavers Cooperative', 'kind' => 'partner', 'contact_name' => 'Chenda Ouk', 'phone' => '+855 32 210 755', 'email' => 'hello@takeosilk.example'],
        ])->map(fn (array $supplier) => Supplier::firstOrCreate(['name' => $supplier['name']], $supplier));

        $tier = fn (string $name) => Tier::where('name', $name)->value('id') ?? Tier::factory()->create(['name' => $name])->id;
        $voucher = ['supplier' => 'print', 'unit_cost' => 0.1, 'tracking' => 'batch', 'low_stock_threshold' => 50, 'reorder_point' => 120, 'reorder_qty' => 500];
        $rewards = [
            ['name' => '$2 Discount Voucher', 'name_kh' => 'គូប៉ុង $2', 'tier' => 'Silver', 'category' => 'Discount', 'points_cost' => 200, 'description' => 'Get $2 off your next order. Valid for 30 days.', 'stock' => [...$voucher, 'sku' => 'VCH-002'], 'opening' => [[0, 799, 'VCH-2026Q3', 78], [1, 200, 'VCH-2026Q3', 78]]],
            ['name' => '$5 Discount Voucher', 'name_kh' => 'គូប៉ុង $5', 'tier' => 'Gold', 'category' => 'Discount', 'points_cost' => 500, 'description' => 'Get $5 off your next order. Valid for 30 days.', 'stock' => [...$voucher, 'sku' => 'VCH-005'], 'opening' => [[0, 400, 'VCH-2026Q3', 78], [1, 100, 'VCH-2026Q3', 78]]],
            ['name' => 'Free Kampot Coffee', 'name_kh' => 'កាហ្វេកំពតឥតគិតថ្លៃ', 'tier' => 'Silver', 'category' => 'Free Product', 'points_cost' => 400, 'description' => 'A 250g bag of Kampot pepper coffee.', 'stock' => ['sku' => 'FP-KPC-250', 'supplier' => 'kampot', 'unit_cost' => 4.5, 'tracking' => 'batch', 'low_stock_threshold' => 8, 'reorder_point' => 15, 'reorder_qty' => 40], 'opening' => [[0, 30, 'KPC-0726', 18], [0, 35, 'KPC-0926', 190]]],
            ['name' => 'Free Shipping Voucher', 'name_kh' => 'ដឹកជញ្ជូនឥតគិតថ្លៃ', 'tier' => 'Silver', 'category' => 'Shipping', 'points_cost' => 150, 'description' => 'Free shipping on your next order, any amount. Valid 14 days.', 'stock' => [...$voucher, 'sku' => 'VCH-SHIP'], 'opening' => [[0, 799, 'VCH-2026Q3', 78], [1, 200, 'VCH-2026Q3', 78]]],
            ['name' => 'Khmer Snack Box', 'name_kh' => 'ប្រអប់អាហារ', 'tier' => 'Gold', 'category' => 'Free Product', 'points_cost' => 800, 'description' => 'A box of traditional Khmer snacks.', 'stock' => ['sku' => 'FP-SNK-BOX', 'supplier' => 'snack', 'unit_cost' => 6, 'tracking' => 'batch', 'low_stock_threshold' => 5, 'reorder_point' => 10, 'reorder_qty' => 30, 'out_of_stock' => 'hide'], 'opening' => [[1, 4, 'SNK-08', -5], [0, 26, 'SNK-09', 55]]],
            ['name' => '$15 Platinum Voucher', 'name_kh' => 'គូប៉ុង Platinum $15', 'tier' => 'Platinum', 'category' => 'Discount', 'points_cost' => 1200, 'description' => 'Exclusive $15 voucher for Platinum members only.', 'stock' => [...$voucher, 'sku' => 'VCH-015', 'low_stock_threshold' => 10, 'reorder_point' => 25, 'reorder_qty' => 100], 'opening' => [[0, 80, 'VCH-2026Q3', 78], [1, 20, 'VCH-2026Q3', 78]]],
            ['name' => 'Silk Krama Gift', 'name_kh' => 'ក្រមាសូត្រ​ជា​អំណោយ', 'tier' => 'Platinum', 'category' => 'Free Product', 'points_cost' => 2000, 'description' => 'A hand-woven Takeo silk krama.', 'stock' => ['sku' => 'GF-KRAMA', 'supplier' => 'krama', 'unit_cost' => 18, 'tracking' => 'serial', 'low_stock_threshold' => 2, 'reorder_point' => 4, 'reorder_qty' => 10, 'allow_backorder' => true], 'variants' => [['Standard', 'Red', 'GF-KRAMA-RED', 'R'], ['Standard', 'Indigo', 'GF-KRAMA-IND', 'I'], ['Standard', 'Gold', 'GF-KRAMA-GLD', 'G']]],
            ['name' => 'Coffee Tasting Kit', 'name_kh' => 'ឧបករណ៍ ​សាកល្បងកាហ្វេ', 'tier' => 'Gold', 'category' => 'Free Product', 'points_cost' => 600, 'description' => 'Three single-origin Cambodian coffees to taste at home.', 'stock' => ['sku' => 'GF-TASTE', 'supplier' => 'kampot', 'unit_cost' => 9.5, 'tracking' => 'none', 'low_stock_threshold' => 3, 'reorder_point' => 6, 'reorder_qty' => 12], 'variants' => [['Small', '', 'GF-TASTE-S', 12], ['Large', '', 'GF-TASTE-L', 8]]],
        ];

        $actor = User::whereHas('role', fn ($query) => $query->where('code', Role::SUPER_ADMIN))->first();
        $seedLedger = $actor && ! RewardStockMovement::exists();

        foreach ($rewards as $data) {
            $reward = Reward::firstOrCreate(['name' => $data['name']], [
                ...Arr::only($data, ['name_kh', 'category', 'points_cost', 'description']),
                'min_tier_id' => $tier($data['tier']),
                'is_active' => true,
            ]);
            $stock = $data['stock'];
            $item = $reward->stockItem()->firstOrCreate([], [
                ...Arr::except($stock, 'supplier'),
                'supplier_id' => $suppliers[$stock['supplier']]->id,
                'out_of_stock' => $stock['out_of_stock'] ?? 'disable',
                'allow_backorder' => $stock['allow_backorder'] ?? false,
            ]);
            $variants = collect($data['variants'] ?? [])->map(fn (array $variant) => [
                $reward->variants()->firstOrCreate(['sku' => $variant[2]], ['size' => $variant[0], 'color' => $variant[1]]),
                $variant[3],
            ]);

            if (! $seedLedger) {
                continue;
            }

            $receive = fn (array $fields) => RewardStockMovement::create([
                'reward_id' => $reward->id,
                'actor_id' => $actor->id,
                'supplier_id' => $item->supplier_id,
                'type' => 'RECEIVE',
                'unit_cost' => $item->unit_cost,
                'source' => $item->tracking === 'serial' ? 'DONATION' : 'PURCHASE',
                'reason' => 'Opening balance',
                ...$fields,
            ]);

            foreach ($data['opening'] ?? [] as [$location, $qty, $batch, $expiresInDays]) {
                $receive(['location_id' => $locations[$location]->id, 'on_hand_delta' => $qty, 'batch' => $batch, 'expires_at' => now()->addDays($expiresInDays)->toDateString()]);
            }

            foreach ($variants as [$variant, $opening]) {
                // Serial-tracked variants get three numbered units each; others get their opening quantity.
                is_string($opening)
                    ? $receive(['location_id' => $locations[0]->id, 'variant_id' => $variant->id, 'on_hand_delta' => 3, 'serials' => array_map(fn (int $n) => "KR-{$opening}-00{$n}", [1, 2, 3])])
                    : $receive(['location_id' => $locations[0]->id, 'variant_id' => $variant->id, 'on_hand_delta' => $opening]);
            }
        }
    }
}
