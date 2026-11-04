<?php

namespace App\Http\Controllers;

use App\Models\Badge;
use App\Models\Campaign;
use App\Models\Category;
use App\Models\Product;
use App\Models\Reward;
use App\Models\RewardVariant;
use App\Models\Setting;
use App\Models\Tier;
use App\Services\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class StorefrontController extends Controller
{
    /**
     * Everything guests and customers browse, in one read: the live catalog, rewards with what is available,
     * tiers, badges, running campaigns and the exchange rate. Costs and suppliers stay staff-only.
     */
    public function index(StockLedger $ledger): JsonResponse
    {
        return $this->attempt('Could not load the store.', function () use ($ledger): JsonResponse {
            $availability = $ledger->availability();

            return response()->json([
                'success' => true,
                'message' => 'Storefront retrieved successfully',
                'data' => [
                    'categories' => Category::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->get(['id', 'parent_id', 'name', 'name_kh', 'description', 'is_active']),
                    'products' => Product::query()
                        ->with(['category:id,name,is_active', 'images', 'inventories'])
                        ->whereHas('category', fn ($query) => $query->where('is_active', true))
                        ->latest('id')
                        ->get()
                        ->makeHidden(['cost_price', 'reorder_level']),
                    'rewards' => Reward::query()
                        ->with(['minTier:id,name', 'stockItem', 'variants'])
                        ->where('is_active', true)
                        ->orderBy('id')
                        ->get()
                        ->map(fn (Reward $reward): array => $this->reward($reward, $availability)),
                    'tiers' => Tier::query()->orderBy('min_points')->get(['id', 'name', 'min_points', 'max_points', 'multiplier', 'color']),
                    'badges' => Badge::query()->orderBy('id')->get(['id', 'code', 'name', 'name_kh', 'description', 'icon', 'color', 'metric', 'threshold']),
                    'campaigns' => Campaign::query()->live()->orderBy('sort_order')->orderBy('id')->get(),
                    'exchange_rate' => Setting::integer(Setting::EXCHANGE_RATE),
                ],
            ]);
        });
    }

    /**
     * Shape a reward for customers, with the units available for each option. A blank key is the standard item.
     *
     * @param  Collection<string, int>  $availability
     * @return array<string, mixed>
     */
    private function reward(Reward $reward, Collection $availability): array
    {
        $settings = $reward->stockSettings();

        return [
            ...$reward->only(['id', 'min_tier_id', 'name', 'name_kh', 'description', 'category', 'points_cost', 'image_url', 'is_active']),
            'expires_at' => $reward->expires_at?->toDateString(),
            'min_tier' => $reward->minTier?->only(['id', 'name']),
            'stock' => $availability->filter(fn (int $units, string $key): bool => str_starts_with($key, "{$reward->id}|"))->sum(),
            'stock_item' => $settings->only(['sku', 'tracking', 'out_of_stock', 'allow_backorder']),
            'variants' => $reward->variants->map(fn (RewardVariant $variant) => $variant->only(['id', 'size', 'color', 'sku']))->values(),
            'available' => collect($reward->variantIds())
                ->mapWithKeys(fn (?int $variantId): array => [(string) $variantId => $availability->get("{$reward->id}|{$variantId}", 0)]),
        ];
    }
}
