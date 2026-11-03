<?php

namespace App\Http\Controllers;

use App\Models\Reward;
use App\Models\RewardStockMovement;
use App\Models\RewardVariant;
use App\Models\StockLocation;
use App\Models\Stocktake;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

class StockController extends Controller
{
    /**
     * Everything the inventory pages work from: rewards with their stock settings and variants, locations,
     * suppliers, the full ledger (newest first) and stocktakes. Levels, alerts and reports are replayed from the ledger.
     */
    public function index(): JsonResponse
    {
        return $this->attempt('Could not load stock.', fn (): JsonResponse => response()->json([
            'success' => true,
            'message' => 'Stock retrieved successfully',
            'data' => [
                'rewards' => Reward::query()->with(['minTier:id,name', 'stockItem', 'variants'])->orderBy('id')->get()
                    ->map(fn (Reward $reward) => [
                        ...$reward->only(['id', 'name', 'name_kh', 'description', 'category', 'points_cost', 'image', 'is_active']),
                        'min_tier' => $reward->minTier?->name,
                        'stock_item' => $reward->stockSettings()->toApiArray(),
                        'variants' => $reward->variants->map(fn (RewardVariant $variant) => $variant->only(['id', 'size', 'color', 'sku']))->values(),
                    ]),
                'locations' => StockLocation::query()->orderBy('id')->get(['id', 'name', 'name_kh', 'type', 'is_active']),
                'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name', 'kind', 'contact_name', 'phone', 'email', 'is_active']),
                'movements' => RewardStockMovement::query()->with('actor:id,name')->latest('id')->get()
                    ->map(fn (RewardStockMovement $movement) => $movement->toApiArray()),
                'stocktakes' => Stocktake::query()->with(['lines', 'creator:id,name', 'poster:id,name'])->latest('id')->get()
                    ->map(fn (Stocktake $stocktake) => $stocktake->toApiArray()),
            ],
        ]));
    }
}
