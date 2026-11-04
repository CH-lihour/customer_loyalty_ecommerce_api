<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Placing orders and moving them through their statuses.
 */
class OrderService
{
    public function __construct(private ProductStock $stock, private LoyaltyProgram $loyalty) {}

    /**
     * Turn cart lines into an order, taking the units out of stock. Prices and points come from the
     * catalog, never the request. Payment is collected on delivery: there is no online payment yet.
     *
     * @param  list<array{product_id: int, quantity: int}>  $lines
     *
     * @throws ValidationException when a product is unavailable or short of stock.
     */
    public function place(Customer $customer, array $lines, string $address, string $phone): Order
    {
        return DB::transaction(function () use ($customer, $lines, $address, $phone): Order {
            $quantities = collect($lines)->groupBy('product_id')->map(fn ($group): int => $group->sum('quantity'));
            $products = Product::query()->with('category:id,is_active')->whereKey($quantities->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($quantities->keys()->contains(fn (int $id): bool => ! $products->get($id)?->category?->is_active)) {
                throw ValidationException::withMessages(['items' => 'An item in your cart is no longer available.']);
            }

            $total = $quantities->reduce(fn (float $sum, int $quantity, int $id): float => $sum + $quantity * (float) $products[$id]->price, 0.0);
            $deliveryAddress = $customer->addresses()->firstOrCreate(
                ['street' => $address],
                ['city' => $customer->province ?: 'Phnom Penh', 'country' => 'Cambodia'],
            );

            $order = $customer->orders()->create([
                'address_id' => $deliveryAddress->id,
                'status' => Order::PENDING,
                'total_amount' => $total,
                'ordered_at' => now(),
                'delivery_phone' => $phone,
            ]);

            foreach ($quantities as $id => $quantity) {
                $product = $products[$id];
                $this->stock->take($product, $quantity, $order);
                $order->items()->create([
                    'product_id' => $id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'base_points' => $product->normal_points,
                    'bonus_multiplier' => $product->bonus_multiplier,
                ]);
            }

            $order->payment()->create(['method' => Payment::CASH_ON_DELIVERY, 'status' => Payment::PENDING, 'amount' => $total]);

            ActivityLog::record('ORDER_CREATED', sprintf('Created order %s for $%.2f', $order->reference(), $total), $customer);

            return $order->load(Order::RELATIONS);
        });
    }

    /**
     * Move an order to its next status, along with what that status means for stock, payment and points.
     *
     * @throws ValidationException when the order cannot move to that status.
     */
    public function transition(Order $order, string $status, User $staff): Order
    {
        return DB::transaction(function () use ($order, $status, $staff): Order {
            $order = Order::query()->with(['items.product', 'payment', 'customer'])->lockForUpdate()->findOrFail($order->id);

            if (! in_array($status, Order::TRANSITIONS[$order->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => "{$order->reference()} is {$order->status} and cannot become {$status}.",
                ]);
            }

            $order->update(['status' => $status]);

            match ($status) {
                Order::SHIPPED => $order->shipment()->create(['status' => Shipment::SHIPPED, 'shipped_at' => now()]),
                Order::CANCELLED => $this->cancelled($order),
                Order::COMPLETED => $this->completed($order),
                default => null,
            };

            ActivityLog::record("ORDER_{$status}", "Order {$order->reference()} ".strtolower($status), $order->customer, $staff);

            return $order->load(Order::RELATIONS);
        });
    }

    /**
     * Return a cancelled order's units to stock and void its payment.
     */
    private function cancelled(Order $order): void
    {
        $order->items->each(fn (OrderItem $item) => $this->stock->putBack($item->product, $item->quantity, $order));
        $order->payment?->update(['status' => Payment::CANCELLED]);
    }

    /**
     * Record the cash collected on delivery and give the customer what the order earned.
     */
    private function completed(Order $order): void
    {
        $order->payment?->update(['status' => Payment::PAID, 'paid_at' => now()]);
        $order->shipment()->update(['status' => Shipment::DELIVERED]);
        $this->loyalty->orderCompleted($order);
    }
}
