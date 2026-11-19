<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.khqr.simulate' => true]);
        $tier = Tier::factory()->create(['name' => 'Bronze', 'min_points' => 0, 'multiplier' => 1]);
        $this->customer = Customer::factory()->create(['tier_id' => $tier->id, 'name' => 'Dara Kim']);
    }

    public function test_staff_list_orders_with_filters_and_status_counts(): void
    {
        $this->placeOrder(10);
        $this->placeOrder(20);
        $staff = User::factory()->superAdmin()->create();
        Sanctum::actingAs($staff);
        $this->patchJson('/api/backend/orders/1/status', ['status' => 'CONFIRMED'])->assertOk();

        $this->getJson('/api/backend/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.order_number', 'ORD-000002')
            ->assertJsonPath('data.0.customer.name', 'Dara Kim')
            ->assertJsonPath('data.0.payment.method', 'KHQR')
            ->assertJsonPath('stats.PENDING', 1)
            ->assertJsonPath('stats.CONFIRMED', 1)
            ->assertJsonPath('stats.COMPLETED', 0);

        $this->getJson('/api/backend/orders?status=CONFIRMED')->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_number', 'ORD-000001');
        $this->getJson('/api/backend/orders?search=dara')->assertJsonCount(2, 'data');
        $this->getJson('/api/backend/orders?search=000002')->assertJsonCount(1, 'data');
    }

    public function test_orders_move_along_and_completing_changes_no_points(): void
    {
        $this->placeOrder(10);
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        foreach (['CONFIRMED', 'PROCESSING', 'SHIPPED', 'COMPLETED'] as $status) {
            $this->patchJson('/api/backend/orders/1/status', ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
        }

        $this->assertSame(10, $this->customer->refresh()->points_balance);
        $this->assertDatabaseCount('loyalty_transactions', 1);
        $this->assertDatabaseHas('activity_logs', ['event' => 'ORDER_COMPLETED', 'description' => 'ORD-000001: SHIPPED → COMPLETED']);

        $this->patchJson('/api/backend/orders/1/status', ['status' => 'CANCELLED'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'is COMPLETED and cannot be moved to CANCELLED']);
    }

    public function test_cancelling_takes_back_the_points_and_the_spend(): void
    {
        $this->placeOrder(30);
        $staff = User::factory()->superAdmin()->create();
        Sanctum::actingAs($staff);

        $this->patchJson('/api/backend/orders/1/status', ['status' => 'CANCELLED'])->assertOk();

        $this->customer->refresh();
        $this->assertSame(0, $this->customer->points_balance);
        $this->assertSame(0, $this->customer->qualifying_points);
        $this->assertSame('0.00', $this->customer->total_spent);
        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $this->customer->id, 'order_id' => 1, 'created_by' => $staff->id,
            'type' => 'EARN', 'points' => -30, 'reason' => 'Order ORD-000001 cancelled: points taken back',
        ]);
    }

    public function test_cancelling_is_refused_when_the_points_were_already_spent(): void
    {
        $this->placeOrder(30);
        $this->customer->recordPoints('SPEND', -25, 'Redeemed a voucher');
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->patchJson('/api/backend/orders/1/status', ['status' => 'CANCELLED'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['points']);
        $this->assertDatabaseHas('orders', ['id' => 1, 'status' => 'PENDING']);
    }

    public function test_staff_without_the_orders_page_and_customers_are_refused(): void
    {
        $this->placeOrder(10);

        // A customer token is not a staff token.
        $this->getJson('/api/backend/orders')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->withPages(['points'])->create());
        $this->getJson('/api/backend/orders')->assertForbidden();
        $this->patchJson('/api/backend/orders/1/status', ['status' => 'CONFIRMED'])->assertForbidden();
    }

    public function test_customer_profile_has_tier_spend_and_order_count(): void
    {
        $this->placeOrder(12);

        $this->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.tier', 'Bronze')
            ->assertJsonPath('data.total_spent', '12.00')
            ->assertJsonPath('data.orders_count', 1);
    }

    public function test_guests_see_the_membership_tiers(): void
    {
        $this->getJson('/api/catalog/tiers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Bronze')
            ->assertJsonMissingPath('data.0.customers_count');
    }

    /**
     * Have the customer pay for and place an order of one product worth `$price` and as many points.
     */
    private function placeOrder(int $price): void
    {
        Sanctum::actingAs($this->customer, [], 'customer');
        $product = Product::factory()->create(['price' => $price, 'normal_points' => $price]);
        $md5 = $this->postJson('/api/payments/khqr', ['amount' => $price])->json('data.md5');
        $this->postJson("/api/payments/khqr/{$md5}/simulate")->assertOk();
        $this->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'delivery_address' => 'St. 271, Phnom Penh',
            'delivery_phone' => '012345678',
            'khqr_md5' => $md5,
        ])->assertCreated();
    }
}
