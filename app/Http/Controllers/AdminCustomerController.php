<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Tier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Staff view of registered customers, with their tier, points, spend and order activity.
 */
class AdminCustomerController extends Controller
{
    /**
     * List a page of customers, optionally searched by name, email or phone and filtered by tier name.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load customers.', function () use ($request): JsonResponse {
            $filters = $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'tier' => ['nullable', 'string', 'max:255'],
            ]);

            $customers = self::withActivity(Customer::query())
                ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                    $term = '%'.mb_strtolower($search).'%';
                    $query->where(fn (Builder $query) => $query
                        ->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(email) like ?', [$term])
                        ->orWhereRaw('lower(phone) like ?', [$term]));
                })
                ->when($filters['tier'] ?? null, fn (Builder $query, string $tier) => $query->whereHas('tier', fn (Builder $query) => $query->where('name', $tier)))
                ->orderBy('name')
                ->orderBy('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($customers, 'Customers retrieved successfully');
        });
    }

    /**
     * Move a customer to a tier chosen by staff, or with no tier sent, back to the tier their qualifying points
     * reach. The change is kept in the tier history with the reason.
     */
    public function updateTier(Request $request, Customer $customer): JsonResponse
    {
        return $this->attempt('Could not change the tier.', function () use ($request, $customer): JsonResponse {
            $data = $request->validate([
                'tier_id' => ['nullable', 'integer', Rule::exists('tiers', 'id')],
                'reason' => ['required', 'string', 'max:255'],
            ]);
            $staff = $request->user();

            DB::transaction(function () use ($customer, $data, $staff): void {
                $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
                $to = isset($data['tier_id'])
                    ? Tier::findOrFail($data['tier_id'])
                    : (Tier::query()->where('min_points', '<=', $customer->qualifying_points)->orderByDesc('min_points')->first()
                        ?? Tier::query()->orderBy('min_points')->firstOrFail());
                $from = Tier::find($customer->tier_id);

                if ($from?->id === $to->id) {
                    return;
                }

                $customer->update(['tier_id' => $to->id]);
                $reason = 'Staff: '.trim($data['reason']);

                if ($from) {
                    DB::table('tier_histories')->insert([
                        'customer_id' => $customer->id,
                        'from_tier_id' => $from->id,
                        'to_tier_id' => $to->id,
                        'reason' => $reason,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('activity_logs')->insert([
                    'customer_id' => $customer->id,
                    'user_id' => $staff->id,
                    'event' => 'TIER_CHANGED',
                    'description' => ($from?->name ?? 'No tier')." → {$to->name} for {$customer->name}: ".trim($data['reason']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Tier updated',
                'data' => self::withActivity(Customer::query())->findOrFail($customer->id),
            ]);
        });
    }

    /**
     * Add the fields the customer tables show: tier, count of orders that were not cancelled, and when the
     * latest of those was placed.
     *
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    public static function withActivity(Builder $query): Builder
    {
        return $query
            ->select(['id', 'tier_id', 'name', 'email', 'phone', 'province', 'avatar', 'referral_code', 'points_balance', 'qualifying_points', 'total_spent', 'is_active', 'created_at'])
            ->with('tier:id,name')
            ->withCount(['orders' => fn (Builder $query) => $query->where('status', '!=', Order::STATUS_CANCELLED)])
            ->addSelect(['last_order_at' => Order::query()
                ->select('ordered_at')
                ->whereColumn('orders.customer_id', 'customers.id')
                ->where('status', '!=', Order::STATUS_CANCELLED)
                ->latest('ordered_at')
                ->limit(1)]);
    }
}
