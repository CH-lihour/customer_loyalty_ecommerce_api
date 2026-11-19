<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Redemption;
use App\Models\Referral;
use App\Models\Reward;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Tier $bronze;

    private Tier $gold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bronze = Tier::factory()->create(['name' => 'Bronze', 'min_points' => 0]);
        $this->gold = Tier::factory()->create(['name' => 'Gold', 'min_points' => 1000]);
    }

    private function order(Customer $customer, string $status, int $daysAgo, float $total = 10): void
    {
        $customer->orders()->create(['status' => $status, 'total_amount' => $total, 'ordered_at' => now()->subDays($daysAgo), 'points_earned' => 0]);
    }

    public function test_staff_list_customers_with_tier_orders_and_last_order(): void
    {
        $dara = Customer::factory()->create(['tier_id' => $this->gold->id, 'name' => 'Dara Kim', 'email' => 'dara@example.com', 'total_spent' => 25]);
        Customer::factory()->create(['tier_id' => $this->bronze->id, 'name' => 'Sokha Lim']);
        $this->order($dara, Order::STATUS_COMPLETED, 3);
        $this->order($dara, Order::STATUS_PENDING, 1);
        $this->order($dara, Order::STATUS_CANCELLED, 0);
        Sanctum::actingAs(User::factory()->withPages(['customers'])->create());

        $this->getJson('/api/backend/customers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Dara Kim')
            ->assertJsonPath('data.0.tier.name', 'Gold')
            ->assertJsonPath('data.0.orders_count', 2)
            ->assertJsonPath('data.0.total_spent', '25.00')
            ->assertJsonMissingPath('data.0.password');
        $this->assertStringStartsWith(now()->subDay()->toDateString(), $this->getJson('/api/backend/customers?search=dara')->json('data.0.last_order_at'));

        $this->getJson('/api/backend/customers?search=DARA@')->assertJsonCount(1, 'data');
        $this->getJson('/api/backend/customers?tier=Bronze')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sokha Lim');
    }

    public function test_staff_change_a_tier_and_auto_puts_it_back(): void
    {
        $customer = Customer::factory()->create(['tier_id' => $this->bronze->id, 'qualifying_points' => 10]);
        $staff = User::factory()->withPages(['customers'])->create();
        Sanctum::actingAs($staff);

        $this->patchJson("/api/backend/customers/{$customer->id}/tier", ['tier_id' => $this->gold->id])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson("/api/backend/customers/{$customer->id}/tier", ['tier_id' => $this->gold->id, 'reason' => 'VIP'])
            ->assertOk()
            ->assertJsonPath('data.tier.name', 'Gold');
        $this->assertDatabaseHas('tier_histories', ['customer_id' => $customer->id, 'from_tier_id' => $this->bronze->id, 'to_tier_id' => $this->gold->id, 'reason' => 'Staff: VIP']);
        $this->assertDatabaseHas('activity_logs', ['customer_id' => $customer->id, 'user_id' => $staff->id, 'event' => 'TIER_CHANGED']);

        $this->patchJson("/api/backend/customers/{$customer->id}/tier", ['tier_id' => null, 'reason' => 'Back to normal'])
            ->assertOk()
            ->assertJsonPath('data.tier.name', 'Bronze');
    }

    public function test_dashboard_counts_come_from_the_database(): void
    {
        $dara = Customer::factory()->create(['tier_id' => $this->gold->id, 'total_spent' => 50]);
        $sokha = Customer::factory()->create(['tier_id' => $this->bronze->id, 'total_spent' => 5]);
        Customer::factory()->create(['tier_id' => $this->bronze->id]);
        $this->order($dara, Order::STATUS_COMPLETED, 2);
        $this->order($dara, Order::STATUS_PENDING, 1);
        $this->order($sokha, Order::STATUS_COMPLETED, 45);
        $dara->recordPoints(LoyaltyTransaction::TYPE_EARN, 600, 'Purchase');
        $sokha->recordPoints(LoyaltyTransaction::TYPE_REFERRAL, 100, 'Referral');
        $reward = Reward::factory()->for($this->bronze, 'minTier')->create(['points_cost' => 200, 'stock' => 5, 'is_active' => true]);
        Redemption::redeem($dara, $reward);
        Redemption::redeem($dara->refresh(), Reward::factory()->for($this->bronze, 'minTier')->create(['points_cost' => 100, 'stock' => 5, 'is_active' => true]))
            ->release(Redemption::STATUS_CANCELLED, 'Changed mind');
        Referral::factory()->create(['referrer_id' => $dara->id, 'referred_id' => $sokha->id, 'is_completed' => true]);
        Sanctum::actingAs(User::factory()->withPages(['dashboard'])->create());

        $this->getJson('/api/backend/dashboard')
            ->assertOk()
            ->assertJsonPath('data.total_customers', 3)
            ->assertJsonPath('data.active_customers', 1)
            ->assertJsonPath('data.total_orders', 3)
            ->assertJsonPath('data.completed_orders', 2)
            ->assertJsonPath('data.total_points_issued', 700)
            ->assertJsonPath('data.total_points_redeemed', 200)
            ->assertJsonPath('data.total_rewards_redeemed', 1)
            ->assertJsonPath('data.referral_conversions', 1)
            ->assertJsonPath('data.repeat_customers', 1)
            ->assertJsonPath('data.one_time_customers', 1)
            ->assertJsonPath('data.inactive_30', 2)
            ->assertJsonPath('data.inactive_60', 1)
            ->assertJsonPath('data.tier_counts.0', ['tier' => 'Bronze', 'count' => 2])
            ->assertJsonPath('data.tier_counts.1', ['tier' => 'Gold', 'count' => 1])
            ->assertJsonCount(3, 'data.recent_orders')
            ->assertJsonPath('data.top_customers.0.id', $dara->id)
            ->assertJsonPath('data.engagement.0.id', $dara->id);

        $this->getJson('/api/backend/analytics')->assertForbidden();
        $this->getJson('/api/backend/customers')->assertForbidden();
    }
}
