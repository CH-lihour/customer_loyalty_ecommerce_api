<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Product stock, kept per warehouse in `inventories` with every change written to `stock_movements`.
 * Reward stock is separate: see StockLedger.
 */
class ProductStock
{
    /**
     * Set how many units can be sold, recording the difference as an adjustment.
     */
    public function set(Product $product, int $quantity): void
    {
        DB::transaction(function () use ($product, $quantity): void {
            $inventory = $this->inventoryFor($product);
            $change = $quantity - $inventory->available();

            if ($change !== 0) {
                $this->move($inventory, $change, ProductStockMovement::ADJUST);
            }
        });
    }

    /**
     * Take sold units out of stock.
     *
     * @throws ValidationException when too few units are left.
     */
    public function take(Product $product, int $quantity, Order $order): void
    {
        $inventory = $this->inventoryFor($product);

        if ($quantity > $inventory->available()) {
            throw ValidationException::withMessages([
                'items' => "Only {$inventory->available()} of {$product->name} left in stock.",
            ]);
        }

        $this->move($inventory, -$quantity, ProductStockMovement::SALE, $order);
    }

    /**
     * Put the units of a cancelled order back into stock.
     */
    public function putBack(Product $product, int $quantity, Order $order): void
    {
        $this->move($this->inventoryFor($product), $quantity, ProductStockMovement::RETURN, $order);
    }

    /**
     * The product's row in the main warehouse, locked so concurrent changes apply one at a time.
     */
    private function inventoryFor(Product $product): Inventory
    {
        $warehouseId = Warehouse::main()->id;

        Inventory::query()->firstOrCreate(['product_id' => $product->id, 'warehouse_id' => $warehouseId]);

        return Inventory::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Change the level and add the matching ledger row.
     */
    private function move(Inventory $inventory, int $change, string $type, ?Order $order = null): void
    {
        $inventory->update(['qty_on_hand' => $inventory->qty_on_hand + $change]);

        ProductStockMovement::query()->create([
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'type' => $type,
            'quantity' => $change,
            'reference_type' => $order?->getMorphClass(),
            'reference_id' => $order?->id,
        ]);
    }
}
