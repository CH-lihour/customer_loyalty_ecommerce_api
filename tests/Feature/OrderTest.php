<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Referral;
use App\Models\Tier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private Tier $bronze;

    private Tier $silver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.khqr.simulate' => true, 'services.khqr.khr_rate' => 4000, 'services.khqr.khr_tolerance' => 0.02]);
        $this->bronze = Tier::factory()->create(['name' => 'Bronze', 'min_points' => 0, 'multiplier' => 1]);
        $this->silver = Tier::factory()->create(['name' => 'Silver', 'min_points' => 1000, 'multiplier' => 1.25]);
    }

    public function test_paid_order_is_saved_and_earns_points_into_the_ledger(): void
    {
        $customer = $this->customer();
        $coffee = Product::factory()->create(['price' => 12, 'normal_points' => 12, 'bonus_multiplier' => 2]);
        $rice = Product::factory()->create(['price' => 8.5, 'normal_points' => 8, 'bonus_multiplier' => 1]);
        $md5 = $this->paidKhqr('32.50');

        $this->postJson('/api/orders', $this->order($md5, [[$coffee->id, 2], [$rice->id, 1]]))
            ->assertCreated()
            ->assertJsonPath('message', 'Order placed. You earned 56 points.')
            ->assertJsonPath('data.order_number', 'ORD-000001')
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.total_amount', '32.50')
            ->assertJsonPath('data.points_earned', 56)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.payment.reference', $md5)
            ->assertJsonPath('data.payment.status', 'PAID')
            ->assertJsonPath('data.customer.points_balance', 56)
            ->assertJsonPath('data.referral_bonus', 0);

        $customer->refresh();
        $this->assertSame(56, $customer->points_balance);
        $this->assertSame(56, $customer->qualifying_points);
        $this->assertSame('32.50', $customer->total_spent);
        $this->assertDatabaseHas('loyalty_transactions', [
            'customer_id' => $customer->id, 'order_id' => 1, 'type' => 'EARN', 'points' => 56,
            'balance_before' => 0, 'balance_after' => 56, 'reason' => 'Purchase ORD-000001',
        ]);
        $this->assertDatabaseHas('order_items', ['product_id' => $coffee->id, 'base_points' => 12, 'bonus_multiplier' => 2]);

        $this->getJson('/api/points')->assertOk()->assertJsonPath('data.0.points', 56)->assertJsonPath('data.0.order_id', 1);
        $this->getJson('/api/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.points_earned', 56);
    }

    public function test_tier_and_live_campaign_multiply_the_points_and_reaching_a_tier_moves_the_customer_up(): void
    {
        $customer = $this->customer(['tier_id' => $this->silver->id, 'qualifying_points' => 1000]);
        $gold = Tier::factory()->create(['name' => 'Gold', 'min_points' => 1100, 'multiplier' => 1.5]);
        Campaign::factory()->create(['multiplier' => 2]);
        Campaign::factory()->create(['multiplier' => 3, 'is_active' => false]);
        $product = Product::factory()->create(['price' => 10, 'normal_points' => 10, 'bonus_multiplier' => 1.5]);

        // 10 × 1.5 × 3 = 45 base, × 1.25 Silver × 2 campaign = 112.5, rounded down.
        $this->postJson('/api/orders', $this->order($this->paidKhqr('30.00'), [[$product->id, 3]]))
            ->assertCreated()
            ->assertJsonPath('data.points_earned', 112)
            ->assertJsonPath('data.customer.qualifying_points', 1112)
            ->assertJsonPath('data.customer.tier', 'Gold');

        $this->assertSame($gold->id, $customer->refresh()->tier_id);
        $this->assertDatabaseHas('tier_histories', ['customer_id' => $customer->id, 'from_tier_id' => $this->silver->id, 'to_tier_id' => $gold->id]);
    }

    public function test_customer_sees_the_multipliers_their_next_order_earns_with(): void
    {
        $this->customer(['tier_id' => $this->silver->id]);
        Campaign::factory()->create(['multiplier' => 2]);
        Campaign::factory()->create(['multiplier' => 4, 'start_date' => now()->addDay()->toDateString()]);

        $this->getJson('/api/points/rules')
            ->assertOk()
            ->assertJsonPath('data.tier', 'Silver')
            ->assertJsonPath('data.tier_multiplier', 1.25)
            ->assertJsonPath('data.campaign_multiplier', 2);
    }

    public function test_first_paid_order_converts_the_referral_once(): void
    {
        $referrer = Customer::factory()->create(['tier_id' => $this->bronze->id, 'name' => 'Sokha']);
        $friend = $this->customer(['name' => 'Dara']);
        $referral = Referral::factory()->create(['referrer_id' => $referrer->id, 'referred_id' => $friend->id]);
        $product = Product::factory()->create(['price' => 5, 'normal_points' => 5]);

        $this->postJson('/api/orders', $this->order($this->paidKhqr('5.00'), [[$product->id, 1]]))
            ->assertCreated()
            ->assertJsonPath('data.referral_bonus', Referral::REFERRED_POINTS)
            ->assertJsonPath('data.customer.points_balance', 5 + Referral::REFERRED_POINTS);

        $this->assertTrue($referral->refresh()->is_completed);
        $this->assertNull($referral->completed_by);
        $this->assertSame(Referral::REFERRER_POINTS, $referrer->refresh()->points_balance);
        $this->assertDatabaseHas('loyalty_transactions', ['customer_id' => $referrer->id, 'type' => 'REFERRAL', 'reason' => 'Referral bonus: Dara made their first order']);
        // Referral points are spendable but do not count toward the tier.
        $this->assertSame(5, $friend->refresh()->qualifying_points);

        $this->postJson('/api/orders', $this->order($this->paidKhqr('5.00'), [[$product->id, 1]]))
            ->assertCreated()
            ->assertJsonPath('data.referral_bonus', 0);
        $this->assertSame(Referral::REFERRER_POINTS, $referrer->refresh()->points_balance);
        $this->assertDatabaseCount('loyalty_transactions', 4);
    }

    public function test_khr_payment_is_checked_at_the_configured_rate(): void
    {
        $this->customer();
        $product = Product::factory()->create(['price' => 10, 'normal_points' => 10]);

        $this->postJson('/api/orders', $this->order($this->paidKhqr('30000', 'KHR'), [[$product->id, 1]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['khqr_md5' => 'does not match the order total (40,000 KHR)']);

        $this->postJson('/api/orders', $this->order($this->paidKhqr('40000', 'KHR'), [[$product->id, 1]]))
            ->assertCreated()
            ->assertJsonPath('data.payment.currency', 'KHR')
            ->assertJsonPath('data.payment.paid_amount', '40000')
            ->assertJsonPath('data.total_amount', '10.00');
    }

    public function test_an_order_needs_a_paid_matching_unused_khqr_code_of_its_own(): void
    {
        $this->customer();
        $product = Product::factory()->create(['price' => 10, 'normal_points' => 10]);
        $items = [[$product->id, 1]];

        $unpaid = $this->postJson('/api/payments/khqr', ['amount' => 10])->json('data.md5');
        $this->postJson('/api/orders', $this->order($unpaid, $items))
            ->assertUnprocessable()->assertJsonValidationErrors(['khqr_md5' => 'has not been paid']);

        $this->postJson('/api/orders', $this->order($this->paidKhqr('9.00'), $items))
            ->assertUnprocessable()->assertJsonValidationErrors(['khqr_md5' => 'does not match']);

        $md5 = $this->paidKhqr('10.00');
        $this->postJson('/api/orders', $this->order($md5, $items))->assertCreated();
        $this->postJson('/api/orders', $this->order($md5, $items))
            ->assertUnprocessable()->assertJsonValidationErrors(['khqr_md5' => 'already paid for order ORD-000001']);

        Sanctum::actingAs(Customer::factory()->create(['tier_id' => $this->bronze->id]), [], 'customer');
        $this->postJson('/api/orders', $this->order($this->paidKhqr('10.00'), $items))->assertCreated();
        $this->postJson('/api/orders', $this->order($md5, $items))
            ->assertUnprocessable()->assertJsonValidationErrors(['khqr_md5' => 'not found']);
        $this->assertDatabaseCount('orders', 2);
    }

    public function test_order_input_is_validated_and_guests_are_refused(): void
    {
        $this->postJson('/api/orders', [])->assertUnauthorized();

        $this->customer();
        $deleted = Product::factory()->create();
        $deleted->delete();

        $this->postJson('/api/orders', ['items' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items', 'delivery_address', 'delivery_phone', 'khqr_md5']);
        $this->postJson('/api/orders', $this->order(str_repeat('a', 32), [[$deleted->id, 1], [999, 0]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.product_id' => 'no longer sold', 'items.1.product_id', 'items.1.qty']);
    }

    public function test_customers_only_see_their_own_orders(): void
    {
        $this->customer();
        $product = Product::factory()->create(['price' => 10, 'normal_points' => 10]);
        $this->postJson('/api/orders', $this->order($this->paidKhqr('10.00'), [[$product->id, 1]]))->assertCreated();

        Sanctum::actingAs(Customer::factory()->create(['tier_id' => $this->bronze->id]), [], 'customer');
        $this->getJson('/api/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_guests_browse_active_categories_and_products_without_staff_fields(): void
    {
        $shown = Category::factory()->create(['is_active' => true]);
        $hidden = Category::factory()->create(['is_active' => false]);
        Product::factory()->create(['category_id' => $shown->id, 'cost_price' => 3]);
        Product::factory()->create(['category_id' => $hidden->id]);

        $this->getJson('/api/catalog/categories')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $shown->id);
        $this->getJson('/api/catalog/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category.id', $shown->id)
            ->assertJsonMissingPath('data.0.cost_price')
            ->assertJsonMissingPath('data.0.reorder_level');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        $customer = Customer::factory()->create(['tier_id' => $this->bronze->id, ...$attributes]);
        Sanctum::actingAs($customer, [], 'customer');

        return $customer;
    }

    /**
     * Make a KHQR code for the signed-in customer and pretend to pay it.
     */
    private function paidKhqr(string $amount, string $currency = 'USD'): string
    {
        $md5 = $this->postJson('/api/payments/khqr', ['amount' => $amount, 'currency' => $currency])->assertCreated()->json('data.md5');
        $this->postJson("/api/payments/khqr/{$md5}/simulate")->assertOk();

        return $md5;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $items
     * @return array<string, mixed>
     */
    private function order(string $md5, array $items): array
    {
        return [
            'items' => array_map(fn (array $item) => ['product_id' => $item[0], 'qty' => $item[1]], $items),
            'delivery_address' => 'St. 271, Phnom Penh',
            'delivery_phone' => '012345678',
            'khqr_md5' => $md5,
        ];
    }
}
