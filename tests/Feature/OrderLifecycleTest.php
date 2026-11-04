<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Referral;
use App\Models\Shipment;
use App\Models\Tier;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ProductStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Tier $bronze;

    private Tier $silver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->superAdmin()->create();
        Sanctum::actingAs($this->staff);
        $this->bronze = Tier::factory()->create(['name' => 'Bronze', 'min_points' => 0, 'multiplier' => 1]);
        $this->silver = Tier::factory()->create(['name' => 'Silver', 'min_points' => 100, 'multiplier' => 1.5]);
    }

    /**
     * An order for a product worth the given points per unit.
     */
    private function order(Customer $customer, int $points = 10, float $bonus = 1, int $quantity = 1): Order
    {
        $product = Product::factory()->create(['price' => 20, 'normal_points' => $points, 'bonus_multiplier' => $bonus]);
        app(ProductStock::class)->set($product, 10);

        return app(OrderService::class)->place($customer, [['product_id' => $product->id, 'quantity' => $quantity]], '12 Street 240', '012345678');
    }

    /**
     * Move an order to a status through the API.
     */
    private function setStatus(Order $order, string $status): TestResponse
    {
        return $this->patchJson("/api/backend/orders/{$order->id}/status", ['status' => $status]);
    }

    /**
     * Take an order through every status to completed.
     */
    private function complete(Order $order): void
    {
        foreach ([Order::CONFIRMED, Order::PROCESSING, Order::SHIPPED, Order::COMPLETED] as $status) {
            $this->setStatus($order, $status)->assertOk();
        }
    }

    public function test_orders_can_only_take_the_next_step(): void
    {
        $order = $this->order(Customer::factory()->for($this->bronze)->create());

        $this->setStatus($order, Order::COMPLETED)
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', "{$order->reference()} is PENDING and cannot become COMPLETED.");

        $this->setStatus($order, Order::CONFIRMED)->assertOk()->assertJsonPath('data.status', Order::CONFIRMED);
        $this->setStatus($order, 'LOST')->assertUnprocessable();
    }

    public function test_cancelling_returns_stock_and_voids_payment_once(): void
    {
        $order = $this->order(Customer::factory()->for($this->bronze)->create(), quantity: 3);
        $product = $order->items->first()->product;
        $this->assertSame(7, $product->fresh()->stock);

        $this->setStatus($order, Order::CANCELLED)->assertOk()->assertJsonPath('data.payment.status', Payment::CANCELLED);
        $this->assertSame(10, $product->fresh()->stock);

        $this->setStatus($order, Order::CANCELLED)->assertUnprocessable();
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_shipped_orders_cannot_be_cancelled(): void
    {
        $order = $this->order(Customer::factory()->for($this->bronze)->create());

        foreach ([Order::CONFIRMED, Order::PROCESSING, Order::SHIPPED] as $status) {
            $this->setStatus($order, $status)->assertOk();
        }

        $this->setStatus($order, Order::CANCELLED)->assertUnprocessable();
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'status' => Shipment::SHIPPED]);
    }

    public function test_completing_an_order_earns_points_scaled_by_tier_and_campaign(): void
    {
        $customer = Customer::factory()->for($this->silver)->create();
        Campaign::factory()->create(['multiplier' => 2]);
        Campaign::factory()->create(['multiplier' => 1.5]);
        $order = $this->order($customer, points: 10, bonus: 2, quantity: 3);

        $this->complete($order);

        // 10 × 2 × 3 = 60 base, × 1.5 tier × 2 best campaign.
        $this->assertSame(180, $order->fresh()->points_earned);
        $customer->refresh();
        $this->assertSame(180, $customer->points_balance);
        $this->assertSame(180, $customer->qualifying_points);
        $this->assertSame(60.0, (float) $customer->total_spent);
        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $customer->id,
            'order_id' => $order->id,
            'type' => LoyaltyTransaction::TYPE_EARN,
            'points' => 180,
        ]);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => Payment::PAID]);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'status' => Shipment::DELIVERED]);
    }

    public function test_cancelled_orders_earn_nothing(): void
    {
        $customer = Customer::factory()->for($this->bronze)->create();
        $this->setStatus($this->order($customer), Order::CANCELLED)->assertOk();

        $this->assertSame(0, $customer->fresh()->points_balance);
        $this->assertDatabaseCount('loyalty_transactions', 0);
    }

    public function test_enough_points_move_the_customer_up_a_tier(): void
    {
        $customer = Customer::factory()->for($this->bronze)->create();

        $this->complete($this->order($customer, points: 120));

        $this->assertSame($this->silver->id, $customer->fresh()->tier_id);
        $this->assertDatabaseHas('tier_histories', [
            'customer_id' => $customer->id,
            'from_tier_id' => $this->bronze->id,
            'to_tier_id' => $this->silver->id,
        ]);
    }

    public function test_a_locked_tier_stays_when_points_would_change_it(): void
    {
        $customer = Customer::factory()->for($this->bronze)->create(['tier_locked' => true]);

        $this->complete($this->order($customer, points: 120));

        $this->assertSame($this->bronze->id, $customer->fresh()->tier_id);
        $this->assertDatabaseCount('tier_histories', 0);
    }

    public function test_the_referral_bonus_is_paid_once_on_the_first_completed_order(): void
    {
        $referrer = Customer::factory()->for($this->bronze)->create();
        $customer = Customer::factory()->for($this->bronze)->create();
        $referral = Referral::factory()->create(['referrer_id' => $referrer->id, 'referred_id' => $customer->id]);

        $this->complete($this->order($customer, points: 10));
        $this->complete($this->order($customer, points: 10));

        $this->assertTrue($referral->fresh()->is_completed);
        $this->assertSame(100, $referrer->fresh()->points_balance);
        // 50 referral bonus plus 10 from each order; the bonus does not count toward the tier.
        $this->assertSame(70, $customer->fresh()->points_balance);
        $this->assertSame(20, $customer->fresh()->qualifying_points);
        $this->assertSame(2, LoyaltyTransaction::query()->where('type', LoyaltyTransaction::TYPE_REFERRAL)->count());
    }

    public function test_badges_are_awarded_once_when_a_threshold_is_reached(): void
    {
        $customer = Customer::factory()->for($this->bronze)->create();
        $first = Badge::factory()->create(['code' => 'FIRST', 'metric' => 'orders', 'threshold' => 1]);
        $big = Badge::factory()->create(['code' => 'BIG', 'metric' => 'spend', 'threshold' => 1000]);

        $this->complete($this->order($customer));
        $this->complete($this->order($customer));

        $this->assertSame([$first->id], $customer->badges()->pluck('badges.id')->all());
        $this->assertDatabaseMissing('customer_badges', ['badge_id' => $big->id]);
    }

    public function test_orders_can_be_listed_and_filtered(): void
    {
        $customer = Customer::factory()->for($this->bronze)->create();
        $confirmed = $this->order($customer);
        $this->setStatus($confirmed, Order::CONFIRMED)->assertOk();
        $this->order($customer);

        $this->getJson('/api/backend/orders?status=CONFIRMED')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $confirmed->reference())
            ->assertJsonPath('meta.total', 1);
    }

    public function test_orders_need_the_orders_page_to_change(): void
    {
        $order = $this->order(Customer::factory()->for($this->bronze)->create());

        Sanctum::actingAs(User::factory()->withPages(['profile'])->create());
        $this->getJson('/api/backend/orders')->assertForbidden();

        Sanctum::actingAs(User::factory()->withPages(['dashboard'])->create());
        $this->getJson('/api/backend/orders')->assertOk();
        $this->setStatus($order, Order::CONFIRMED)->assertForbidden();

        Sanctum::actingAs(User::factory()->withPages(['orders'])->create());
        $this->setStatus($order, Order::CONFIRMED)->assertOk();
    }
}
