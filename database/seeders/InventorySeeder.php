<?php

namespace Database\Seeders;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class InventorySeeder extends Seeder
{
    /**
     * Give every product opening stock in the main warehouse. Products that already have stock keep it.
     */
    public function run(): void
    {
        $warehouseId = Warehouse::main()->id;

        Product::query()->orderBy('id')->pluck('id')->each(fn (int $productId) => Inventory::query()->firstOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
            ['qty_on_hand' => 20 + ($productId * 7) % 60],
        ));
    }
}
