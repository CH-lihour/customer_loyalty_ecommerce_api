<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Redemption;
use App\Models\Reward;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RedemptionTest extends TestCase
{
    use RefreshDatabase;

    private Tier $bronze;

    private Tier $gold;

    private Customer $customer;

    private Reward $coffee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bronze = Tier::factory()->create(['name' => 'Bronze', 'min_points' => 0]);
        $this->gold = Tier::factory()->create(['name' => 'Gold', 'min_points' => 1000]);
        $this->customer = Customer::factory()->create(['tier_id' => $this->bronze->id, 'name' => 'Dara Kim']);
        $this->customer->recordPoints(LoyaltyTransaction::TYPE_ADJUST, 500, 'Start');
        $this->coffee = Reward::factory()->for($this->bronze, 'minTier')->create(['name' => 'Free Coffee', 'points_cost' => 200, 'stock' => 2, 'is_active' => true]);
    }

    public function test_catalog_lists_active_unexpired_rewards_for_guests(): void
    {
        Reward::factory()->for($this->bronze, 'minTier')->create(['name' => 'Hidden', 'is_active' => false]);
        Reward::factory()->for($this->bronze, 'minTier')->create(['name' => 'Old', 'is_active' => true, 'expires_at' => now()->subDay()]);
        Reward::factory()->for($this->gold, 'minTier')->create(['name' => 'Gold Box', 'points_cost' => 900, 'is_active' => true, 'expires_at' => today()]);

        $this->getJson('/api/catalog/rewards')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Free Coffee')
            ->assertJsonPath('data.0.stock', 2)
            ->assertJsonPath('data.1.min_tier.name', 'Gold');
    }

    public function test_redeeming_spends_points_takes_stock_and_holds_the_reward(): void
    {
        Sanctum::actingAs($this->customer, [], 'customer');

        $this->postJson('/api/redemptions', ['reward_id' => $this->coffee->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'RESERVED')
            ->assertJsonPath('data.points', 200)
            ->assertJsonPath('data.reward.name', 'Free Coffee')
            ->assertJsonPath('data.customer.points_balance', 300);

        $this->assertSame(1, $this->coffee->refresh()->stock);
        $this->assertDatabaseHas('loyalty_transactions', ['customer_id' => $this->customer->id, 'type' => 'SPEND', 'points' => -200, 'balance_after' => 300]);
        $this->assertSame(0, $this->customer->refresh()->qualifying_points);

        $this->getJson('/api/redemptions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reward.name', 'Free Coffee');
    }

    public function test_redeeming_is_refused_when_the_customer_cannot_have_the_reward(): void
    {
        Sanctum::actingAs($this->customer, [], 'customer');
        $gold = Reward::factory()->for($this->gold, 'minTier')->create(['points_cost' => 10, 'stock' => 5, 'is_active' => true]);
        $empty = Reward::factory()->for($this->bronze, 'minTier')->create(['points_cost' => 10, 'stock' => 0, 'is_active' => true]);
        $dear = Reward::factory()->for($this->bronze, 'minTier')->create(['points_cost' => 900, 'stock' => 5, 'is_active' => true]);
        $off = Reward::factory()->for($this->bronze, 'minTier')->create(['points_cost' => 10, 'stock' => 5, 'is_active' => false]);

        $this->postJson('/api/redemptions', ['reward_id' => $gold->id])->assertUnprocessable()->assertJsonValidationErrors(['reward_id' => 'Gold tier']);
        $this->postJson('/api/redemptions', ['reward_id' => $empty->id])->assertUnprocessable()->assertJsonValidationErrors(['reward_id' => 'out of stock']);
        $this->postJson('/api/redemptions', ['reward_id' => $dear->id])->assertUnprocessable()->assertJsonValidationErrors(['reward_id' => 'need 900 points']);
        $this->postJson('/api/redemptions', ['reward_id' => $off->id])->assertUnprocessable()->assertJsonValidationErrors(['reward_id' => 'no longer available']);

        $this->postJson('/api/redemptions', ['reward_id' => $this->coffee->id])->assertCreated();
        $this->postJson('/api/redemptions', ['reward_id' => $this->coffee->id])->assertUnprocessable()->assertJsonValidationErrors(['reward_id' => 'already redeemed']);

        $this->assertSame(300, $this->customer->refresh()->points_balance);
    }

    public function test_customer_can_cancel_only_their_own_held_redemption(): void
    {
        $redemption = Redemption::redeem($this->customer, $this->coffee);
        Sanctum::actingAs(Customer::factory()->create(['tier_id' => $this->bronze->id]), [], 'customer');
        $this->postJson("/api/redemptions/{$redemption->id}/cancel")->assertNotFound();

        Sanctum::actingAs($this->customer, [], 'customer');
        $this->postJson("/api/redemptions/{$redemption->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.customer.points_balance', 500);
        $this->assertSame(2, $this->coffee->refresh()->stock);
        $this->assertDatabaseHas('loyalty_transactions', ['customer_id' => $this->customer->id, 'type' => 'REFUND', 'points' => 200]);

        $this->postJson("/api/redemptions/{$redemption->id}/cancel")->assertUnprocessable();
    }

    public function test_staff_fulfil_extend_and_cancel_redemptions(): void
    {
        $shipped = Redemption::redeem($this->customer, $this->coffee);
        $other = Customer::factory()->create(['tier_id' => $this->bronze->id, 'name' => 'Sokha Lim']);
        $other->recordPoints(LoyaltyTransaction::TYPE_ADJUST, 200, 'Start');
        $cancelled = Redemption::redeem($other, $this->coffee);
        $staff = User::factory()->withPages(['fulfillment'])->create();
        Sanctum::actingAs($staff);

        $this->getJson('/api/backend/redemptions?status=RESERVED')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.customer.name', 'Dara Kim')
            ->assertJsonPath('stats.RESERVED', 2);
        $this->getJson('/api/backend/redemptions?search=sokha')->assertJsonCount(1, 'data');

        $this->patchJson("/api/backend/redemptions/{$shipped->id}/extend", ['hours' => 24])->assertOk();
        $this->assertTrue($shipped->refresh()->reserved_until->gt(now()->addHours(Redemption::holdHours() + 23)));

        $this->patchJson("/api/backend/redemptions/{$shipped->id}/fulfill", ['method' => 'SHIPPED'])
            ->assertOk()
            ->assertJsonPath('data.status', 'FULFILLED')
            ->assertJsonPath('data.fulfillment', 'SHIPPED');
        $this->patchJson("/api/backend/redemptions/{$shipped->id}/cancel", ['reason' => 'Too late'])->assertUnprocessable();

        $this->patchJson("/api/backend/redemptions/{$cancelled->id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson("/api/backend/redemptions/{$cancelled->id}/cancel", ['reason' => 'Damaged'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.close_reason', 'Damaged');

        $this->getJson('/api/backend/redemptions?closed=1')->assertJsonCount(2, 'data')->assertJsonPath('stats.RESERVED', 0);

        $this->assertSame(200, $other->refresh()->points_balance);
        $this->assertSame(1, $this->coffee->refresh()->stock);
        $this->assertDatabaseHas('loyalty_transactions', ['customer_id' => $other->id, 'type' => 'REFUND', 'created_by' => $staff->id]);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $staff->id, 'event' => 'REDEMPTION_FULFILLED']);
    }

    public function test_overdue_holds_expire_and_refund_when_listed(): void
    {
        $redemption = Redemption::redeem($this->customer, $this->coffee);
        $this->travel(Redemption::holdHours() + 1)->hours();
        Sanctum::actingAs($this->customer, [], 'customer');

        $this->getJson('/api/redemptions')->assertJsonPath('data.0.status', 'EXPIRED');
        $this->assertSame(500, $this->customer->refresh()->points_balance);
        $this->assertSame(2, $this->coffee->refresh()->stock);
        $this->assertSame('EXPIRED', $redemption->refresh()->status);
    }

    public function test_staff_without_the_fulfilment_page_are_refused(): void
    {
        $redemption = Redemption::redeem($this->customer, $this->coffee);
        Sanctum::actingAs(User::factory()->withPages(['rewards'])->create());

        $this->getJson('/api/backend/redemptions')->assertForbidden();
        $this->patchJson("/api/backend/redemptions/{$redemption->id}/fulfill", ['method' => 'SHIPPED'])->assertForbidden();
    }
}
