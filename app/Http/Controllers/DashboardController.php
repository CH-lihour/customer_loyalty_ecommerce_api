<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Redemption;
use App\Models\Referral;
use App\Models\Tier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the staff dashboard and analytics pages, worked out from the database.
 */
class DashboardController extends Controller
{
    /**
     * Customers shown in the engagement list, most recently active first.
     */
    private const ENGAGEMENT_ROWS = 50;

    public function stats(): JsonResponse
    {
        return $this->attempt('Could not load the dashboard.', function (): JsonResponse {
            Redemption::expireOverdue();

            $customers = AdminCustomerController::withActivity(Customer::query())->get();
            $daysSince = fn (Customer $customer): float => $customer->last_order_at
                ? now()->diffInDays($customer->last_order_at, true)
                : INF;
            $inactiveFor = fn (int $days): int => $customers->filter(fn (Customer $c) => $daysSince($c) >= $days)->count();

            $ledger = LoyaltyTransaction::query()->select('type', DB::raw('sum(points) as total'))->groupBy('type')->pluck('total', 'type');
            $sum = fn (string $type): int => (int) ($ledger[$type] ?? 0);
            $issued = (int) LoyaltyTransaction::query()
                ->whereIn('type', [LoyaltyTransaction::TYPE_EARN, LoyaltyTransaction::TYPE_REFERRAL, LoyaltyTransaction::TYPE_ADJUST])
                ->where('points', '>', 0)
                ->sum('points');

            $tierCounts = $customers->countBy('tier_id');

            return response()->json([
                'success' => true,
                'message' => 'Dashboard retrieved successfully',
                'data' => [
                    'total_customers' => $customers->count(),
                    'active_customers' => $customers->filter(fn (Customer $c) => $daysSince($c) < 30)->count(),
                    'total_orders' => Order::query()->count(),
                    'completed_orders' => Order::query()->where('status', Order::STATUS_COMPLETED)->count(),
                    'total_points_issued' => $issued,
                    // Points spent on rewards, less what cancelled or expired redemptions refunded.
                    'total_points_redeemed' => max(0, -$sum(LoyaltyTransaction::TYPE_SPEND) - $sum(LoyaltyTransaction::TYPE_REFUND)),
                    'total_rewards_redeemed' => Redemption::query()->whereIn('status', [Redemption::STATUS_RESERVED, Redemption::STATUS_FULFILLED])->count(),
                    'referral_conversions' => Referral::query()->where('is_completed', true)->count(),
                    'repeat_customers' => $customers->filter(fn (Customer $c) => $c->orders_count > 1)->count(),
                    'one_time_customers' => $customers->filter(fn (Customer $c) => $c->orders_count === 1)->count(),
                    'inactive_30' => $inactiveFor(30),
                    'inactive_60' => $inactiveFor(60),
                    'inactive_90' => $inactiveFor(90),
                    'tier_counts' => Tier::query()->orderBy('min_points')->get(['id', 'name'])
                        ->map(fn (Tier $tier) => ['tier' => $tier->name, 'count' => (int) ($tierCounts[$tier->id] ?? 0)]),
                    'recent_orders' => Order::query()
                        ->with('customer:id,name')
                        ->latest('ordered_at')
                        ->orderByDesc('id')
                        ->limit(5)
                        ->get(['id', 'order_number', 'customer_id', 'status', 'total_amount', 'ordered_at']),
                    'recent_activity' => DB::table('activity_logs')
                        ->latest()
                        ->orderByDesc('id')
                        ->limit(5)
                        ->get(['id', 'event', 'description', 'created_at']),
                    'top_customers' => $customers->sortByDesc(fn (Customer $c) => (float) $c->total_spent)->take(5)->values(),
                    'engagement' => $customers
                        ->sortByDesc(fn (Customer $c) => $c->last_order_at ?? '')
                        ->take(self::ENGAGEMENT_ROWS)
                        ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name, 'last_order_at' => $c->last_order_at])
                        ->values(),
                ],
            ]);
        });
    }
}
