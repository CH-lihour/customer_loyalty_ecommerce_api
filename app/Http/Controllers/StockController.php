<?php

namespace App\Http\Controllers;

use App\Models\Redemption;
use App\Models\RestockRequest;
use App\Models\Reward;
use App\Models\RewardStockMovement;
use App\Models\RewardVariant;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\Stocktake;
use App\Models\Supplier;
use App\Services\RedemptionService;
use Illuminate\Http\JsonResponse;

class StockController extends Controller
{
    /**
     * Everything the inventory pages work from: rewards with their stock settings and variants, locations,
     * suppliers, the full ledger (newest first), stocktakes, redemptions waiting on stock and customers' restock
     * requests. Levels, alerts and reports are replayed from the ledger.
     */
    public function index(RedemptionService $redemptions): JsonResponse
    {
        return $this->attempt('Could not load stock.', function () use ($redemptions): JsonResponse {
            $redemptions->expireOverdue();

            return $this->snapshot();
        });
    }

    /**
     * Build the snapshot response.
     */
    private function snapshot(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Stock retrieved successfully',
            'data' => [
                'rewards' => Reward::query()->with(['minTier:id,name', 'stockItem', 'variants'])->orderBy('id')->get()
                    ->map(fn (Reward $reward) => [
                        ...$reward->only(['id', 'name', 'name_kh', 'description', 'category', 'points_cost', 'image', 'image_url', 'is_active']),
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
                'redemptions' => Redemption::query()->with(Redemption::RELATIONS)->latest('id')->get()
                    ->map(fn (Redemption $redemption) => $redemption->toApiArray()),
                'restock_requests' => RestockRequest::query()->latest('id')->get()
                    ->map(fn (RestockRequest $restockRequest) => $restockRequest->toApiArray()),
                'reservation_hours' => Setting::integer(Setting::RESERVATION_HOURS),
            ],
        ]);
    }
}
