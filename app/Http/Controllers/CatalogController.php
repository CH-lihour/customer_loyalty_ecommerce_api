<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Reward;
use App\Models\Tier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The storefront's read-only catalog, open to guests: active categories and the products in them, without
 * staff-only fields such as cost price, the membership tiers and the rewards points can be spent on. Pages have the same shape as the staff lists.
 */
class CatalogController extends Controller
{
    /**
     * Product fields staff see but shoppers should not.
     *
     * @var list<string>
     */
    private const STAFF_ONLY = ['cost_price', 'reorder_level'];

    /**
     * List a page of active categories.
     */
    public function categories(Request $request): JsonResponse
    {
        return $this->attempt('Could not load categories.', function () use ($request): JsonResponse {
            $categories = Category::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->orderBy('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($categories, 'Categories retrieved successfully');
        });
    }

    /**
     * List a page of products in active categories.
     */
    public function products(Request $request): JsonResponse
    {
        return $this->attempt('Could not load products.', function () use ($request): JsonResponse {
            $products = Product::query()
                ->with(['category:id,name,name_kh,is_active', 'images'])
                ->whereHas('category', fn (Builder $query) => $query->where('is_active', true))
                ->latest('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($products, 'Products retrieved successfully', fn (Product $product) => $product->makeHidden(self::STAFF_ONLY));
        });
    }

    /**
     * List the membership tiers from the entry tier upwards, so the storefront shows the same tiers as the API.
     */
    public function tiers(Request $request): JsonResponse
    {
        return $this->attempt('Could not load tiers.', function () use ($request): JsonResponse {
            $tiers = Tier::query()
                ->select(['id', 'name', 'min_points', 'max_points', 'multiplier', 'color'])
                ->orderBy('min_points')
                ->orderBy('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($tiers, 'Tiers retrieved successfully');
        });
    }

    /**
     * List a page of the rewards customers can redeem now: active and not expired, cheapest first, with the tier
     * each one needs and how many are left.
     */
    public function rewards(Request $request): JsonResponse
    {
        return $this->attempt('Could not load rewards.', function () use ($request): JsonResponse {
            $rewards = Reward::query()
                ->with('minTier:id,name,min_points')
                ->where('is_active', true)
                ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()))
                ->orderBy('points_cost')
                ->orderBy('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($rewards, 'Rewards retrieved successfully');
        });
    }
}
