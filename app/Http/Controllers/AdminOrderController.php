<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff view of customer orders. Points are awarded when an order is paid, so moving it along to Completed
 * changes no points; cancelling it takes the order's points back and removes it from the customer's spend.
 */
class AdminOrderController extends Controller
{
    /**
     * Relations every order response includes.
     *
     * @var list<string>
     */
    private const RELATIONS = ['customer:id,name,email', 'items.product:id,name,name_kh,sku', 'payment'];

    /**
     * List a page of orders, newest first, optionally filtered by status or a search on the order number or
     * customer, with a count of orders in each status.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load the orders.', function () use ($request): JsonResponse {
            $filters = $request->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'status' => ['nullable', Rule::in(Order::STATUSES)],
            ]);

            $orders = Order::query()
                ->with(self::RELATIONS)
                ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                    $term = '%'.mb_strtolower($search).'%';
                    $query->where(fn (Builder $query) => $query
                        ->whereRaw('lower(order_number) like ?', [$term])
                        ->orWhereHas('customer', fn (Builder $query) => $query
                            ->whereRaw('lower(name) like ?', [$term])
                            ->orWhereRaw('lower(email) like ?', [$term])));
                })
                ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
                ->latest('ordered_at')
                ->orderByDesc('id')
                ->paginate($this->perPage($request));

            $response = $this->paginatedResponse($orders, 'Orders retrieved successfully');
            $counts = Order::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

            return $response->setData([
                ...$response->getData(true),
                'stats' => collect(Order::STATUSES)->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)]),
            ]);
        });
    }

    /**
     * Move an order to its next status. Cancelling takes back the points it earned.
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        return $this->attempt('Could not change the order status.', function () use ($request, $order): JsonResponse {
            $data = $request->validate(['status' => ['required', Rule::in(Order::STATUSES)]]);
            $staff = $request->user();

            $order = DB::transaction(function () use ($order, $data, $staff): Order {
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                $from = $order->status;
                $to = $data['status'];

                if (! in_array($to, Order::NEXT_STATUSES[$from] ?? [], true)) {
                    throw ValidationException::withMessages([
                        'status' => "Order {$order->order_number} is {$from} and cannot be moved to {$to}.",
                    ]);
                }

                $order->update(['status' => $to]);
                $description = "{$order->order_number}: {$from} → {$to}";

                if ($to === Order::STATUS_CANCELLED) {
                    $customer = Customer::query()->lockForUpdate()->findOrFail($order->customer_id);

                    if ($order->points_earned > 0) {
                        // A negative EARN row, so the points also stop counting toward the customer's tier.
                        $customer->recordPoints(
                            LoyaltyTransaction::TYPE_EARN,
                            -$order->points_earned,
                            "Order {$order->order_number} cancelled: points taken back",
                            $staff->id,
                            $order->id,
                        );
                        $description .= ", -{$order->points_earned} points";
                    }

                    $customer->refresh();
                    $customer->total_spent = max(0, (float) $customer->total_spent - (float) $order->total_amount);
                    $customer->save();
                }

                DB::table('activity_logs')->insert([
                    'customer_id' => $order->customer_id,
                    'user_id' => $staff->id,
                    'event' => "ORDER_{$to}",
                    'description' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $order;
            });

            return response()->json([
                'success' => true,
                'message' => "Order {$order->order_number} changed to {$order->status}.",
                'data' => $order->load(self::RELATIONS),
            ]);
        });
    }
}
