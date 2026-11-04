<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * List a page of orders, newest first, optionally filtered by status or customer.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load orders.', function () use ($request): JsonResponse {
            $filters = $request->validate([
                'status' => ['nullable', Rule::in(array_keys(Order::TRANSITIONS))],
                'customer_id' => ['nullable', 'integer'],
            ]);

            $orders = Order::query()
                ->with(Order::RELATIONS)
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->when($filters['customer_id'] ?? null, fn ($query, int $customerId) => $query->where('customer_id', $customerId))
                ->latest('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($orders, 'Orders retrieved successfully', fn (Order $order): array => $order->toApiArray());
        });
    }

    /**
     * Move an order to its next status.
     */
    public function updateStatus(Request $request, Order $order, OrderService $orders): JsonResponse
    {
        return $this->attempt('Could not update the order.', function () use ($request, $order, $orders): JsonResponse {
            $data = $request->validate(['status' => ['required', Rule::in(array_keys(Order::TRANSITIONS))]]);

            return response()->json([
                'success' => true,
                'message' => 'Order updated successfully',
                'data' => $orders->transition($order, $data['status'], $request->user())->toApiArray(),
            ]);
        });
    }
}
