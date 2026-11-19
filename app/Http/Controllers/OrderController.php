<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Referral;
use App\Models\Tier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Customer orders. An order is placed once its KHQR code is paid, and that is when it earns points:
 * every product's points × its bonus multiplier × quantity, times the customer's tier multiplier and the
 * best campaign multiplier running today, rounded down. A friend's first order also converts their referral.
 */
class OrderController extends Controller
{
    /**
     * Relations every order response includes.
     *
     * @var list<string>
     */
    private const RELATIONS = ['items.product:id,name,name_kh,sku', 'payment'];

    /**
     * List a page of the signed-in customer's own orders, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->attempt('Could not load your orders.', function () use ($request): JsonResponse {
            $orders = $request->user()->orders()
                ->with(self::RELATIONS)
                ->latest('ordered_at')
                ->orderByDesc('id')
                ->paginate($this->perPage($request));

            return $this->paginatedResponse($orders, 'Orders retrieved successfully');
        });
    }

    /**
     * Place an order paid with a KHQR code: check the payment covers the products, save the order and award
     * its points, and pay the referral bonus when this is a referred friend's first order.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->attempt('Could not place the order.', function () use ($request): JsonResponse {
            $data = $request->validate([
                'items' => ['required', 'array', 'min:1', 'max:50'],
                'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
                'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
                'delivery_address' => ['required', 'string', 'max:500'],
                'delivery_phone' => ['required', 'string', 'max:30'],
                'khqr_md5' => ['required', 'string', 'size:32'],
            ], [
                'items.*.product_id.exists' => 'One of the products in your cart is no longer sold. Remove it and try again.',
            ]);

            /** @var Customer $customer */
            $customer = $request->user();

            // One KHQR code pays for one order, even when the same request is sent twice at once.
            [$order, $referralBonus] = Cache::lock('khqr-order:'.$data['khqr_md5'], 10)->block(5, fn (): array => $this->place($customer, $data));

            return response()->json([
                'success' => true,
                'message' => $order->points_earned > 0
                    ? "Order placed. You earned {$order->points_earned} points."
                    : 'Order placed.',
                'data' => $this->present($order, $customer->refresh(), $referralBonus),
            ], 201);
        });
    }

    /**
     * Save the order and award its points, returning it with the welcome bonus its referral conversion paid.
     *
     * @param  array{items: list<array{product_id: int, qty: int}>, delivery_address: string, delivery_phone: string, khqr_md5: string}  $data
     * @return array{0: Order, 1: int}
     */
    private function place(Customer $customer, array $data): array
    {
        $payment = $this->paidPayment($customer, $data['khqr_md5']);
        $products = Product::query()->whereKey(array_column($data['items'], 'product_id'))->get()->keyBy('id');
        $lines = collect($data['items'])->map(fn (array $item) => [
            'product' => $products[$item['product_id']],
            'qty' => (int) $item['qty'],
        ]);
        $total = round($lines->sum(fn (array $line) => (float) $line['product']->price * $line['qty']), 2);

        $this->checkAmount($payment, $total);

        return DB::transaction(function () use ($customer, $data, $payment, $lines, $total): array {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $isFirstOrder = ! $customer->orders()->exists();
            $points = $this->pointsFor($customer, $lines->all());

            $order = $customer->orders()->create([
                'status' => Order::STATUS_PENDING,
                'total_amount' => $total,
                'ordered_at' => now(),
                'points_earned' => $points,
                'delivery_address' => trim($data['delivery_address']),
                'delivery_phone' => trim($data['delivery_phone']),
            ]);
            $order->forceFill(['order_number' => 'ORD-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)])->save();

            foreach ($lines as $line) {
                $order->items()->create([
                    'product_id' => $line['product']->id,
                    'quantity' => $line['qty'],
                    'unit_price' => $line['product']->price,
                    'base_points' => $line['product']->normal_points,
                    'bonus_multiplier' => $line['product']->bonus_multiplier,
                ]);
            }

            $order->payment()->create([
                'method' => Payment::METHOD_KHQR,
                'reference' => $payment['md5'],
                'transaction_id' => $payment['transaction_id'],
                'status' => Payment::STATUS_PAID,
                'amount' => $total,
                'currency' => $payment['currency'],
                'paid_amount' => $payment['amount'],
                'paid_at' => Carbon::parse($payment['paid_at']),
            ]);

            $customer->total_spent = (float) $customer->total_spent + $total;
            $customer->save();

            $this->log($customer, 'ORDER_PAID', "Paid {$order->order_number} by KHQR: \${$this->usd($total)} ({$payment['amount']} {$payment['currency']})");

            if ($points > 0) {
                $customer->recordPoints(LoyaltyTransaction::TYPE_EARN, $points, "Purchase {$order->order_number}", orderId: $order->id);
                $this->log($customer, 'POINTS_EARNED', "Earned {$points} points from {$order->order_number}");
            }

            // A friend who signed up with a code converts the referral with their first paid order.
            $referral = $customer->referredBy()->where('is_completed', false)->first();
            if ($isFirstOrder && $referral) {
                $referral->complete(order: $order);

                return [$order, Referral::REFERRED_POINTS];
            }

            return [$order, 0];
        });
    }

    /**
     * Load the customer's KHQR payment and make sure it is paid and not already used by an order.
     *
     * @return array<string, mixed>
     */
    private function paidPayment(Customer $customer, string $md5): array
    {
        $payment = Cache::get(KhqrPaymentController::key($md5));

        if (! is_array($payment) || $payment['customer_id'] !== $customer->id) {
            throw ValidationException::withMessages(['khqr_md5' => 'That KHQR payment was not found. Pay again to place the order.']);
        }

        if ($payment['status'] !== KhqrPaymentController::STATUS_PAID) {
            throw ValidationException::withMessages(['khqr_md5' => 'That KHQR code has not been paid yet.']);
        }

        $used = Payment::query()->where('reference', $md5)->with('order:id,order_number')->first();
        if ($used) {
            throw ValidationException::withMessages(['khqr_md5' => "That KHQR payment already paid for order {$used->order?->order_number}."]);
        }

        return $payment;
    }

    /**
     * Make sure the paid amount matches the order total. A KHR payment is compared at the configured rate.
     *
     * @param  array<string, mixed>  $payment
     */
    private function checkAmount(array $payment, float $total): void
    {
        $paid = (float) $payment['amount'];

        if ($payment['currency'] === 'KHR') {
            $expected = $total * (float) config('services.khqr.khr_rate');
            $matches = abs($paid - $expected) <= $expected * (float) config('services.khqr.khr_tolerance');
            $expectedText = number_format($expected, 0, '.', ',').' KHR';
        } else {
            $matches = abs($paid - $total) < 0.005;
            $expectedText = $this->usd($total).' USD';
        }

        if (! $matches) {
            throw ValidationException::withMessages([
                'khqr_md5' => "The KHQR payment ({$payment['amount']} {$payment['currency']}) does not match the order total ({$expectedText}).",
            ]);
        }
    }

    /**
     * Points the order earns, rounded down.
     *
     * @param  list<array{product: Product, qty: int}>  $lines
     */
    private function pointsFor(Customer $customer, array $lines): int
    {
        $base = array_sum(array_map(
            fn (array $line) => $line['product']->normal_points * (float) $line['product']->bonus_multiplier * $line['qty'],
            $lines,
        ));
        $tierMultiplier = (float) (Tier::find($customer->tier_id)?->multiplier ?? 1);
        $campaignMultiplier = Campaign::currentMultiplier();

        // The small nudge keeps 2.3 × 100 = 229.99999 from rounding down to 229.
        return (int) floor($base * $tierMultiplier * $campaignMultiplier + 1e-6);
    }

    /**
     * Shape an order for the customer, with their balance after it.
     *
     * @return array<string, mixed>
     */
    private function present(Order $order, Customer $customer, int $referralBonus): array
    {
        return [
            ...$order->load(self::RELATIONS)->toArray(),
            'customer' => [
                'points_balance' => $customer->points_balance,
                'qualifying_points' => $customer->qualifying_points,
                'tier' => Tier::find($customer->tier_id)?->name,
            ],
            // The welcome bonus this order unlocked, if it converted the customer's referral.
            'referral_bonus' => $referralBonus,
        ];
    }

    private function log(Customer $customer, string $event, string $description): void
    {
        DB::table('activity_logs')->insert([
            'customer_id' => $customer->id,
            'event' => $event,
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function usd(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
