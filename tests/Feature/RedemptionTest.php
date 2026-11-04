<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Redemption;
use App\Models\RestockRequest;
use App\Models\Reward;
use App\Models\RewardStockItem;
use App\Models\RewardVariant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\Tier;
use App\Models\User;
use App\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RedemptionTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private StockLocation $location;

    private Tier $tier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->superAdmin()->create();
        $this->location = StockLocation::factory()->create();
        $this->tier = Tier::factory()->create(['min_points' => 0]);
        $this->customer = $this->customerWith(1000);
        Sanctum::actingAs($this->customer, guard: 'customer');
    }

    /**
     * A customer holding a points balance.
     */
    private function customerWith(int $points): Customer
    {
        $customer = Customer::factory()->for($this->tier)->create();
        $customer->forceFill(['points_balance' => $points])->save();

        return $customer;
    }

    /**
     * A reward in the customer's tier with stock on hand.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function reward(int $stock = 5, array $attributes = []): Reward
    {
        $reward = Reward::factory()->for($this->tier, 'minTier')->create(['points_cost' => 200, ...$attributes]);

        if ($stock) {
            $this->receive($reward, $stock);
        }

        return $reward;
    }

    /**
     * Receive stock directly into the ledger.
     */
    private function receive(Reward $reward, int $quantity, ?int $variantId = null): void
    {
        app(StockLedger::class)->receive($this->staff, [
            'reward_id' => $reward->id,
            'variant_id' => $variantId,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'source' => 'PURCHASE',
            'unit_cost' => 1,
        ]);
    }

    /**
     * Redeem through the API.
     */
    private function redeem(Reward $reward, ?int $variantId = null): TestResponse
    {
        return $this->postJson('/api/redemptions', ['reward_id' => $reward->id, 'variant_id' => $variantId]);
    }

    /**
     * The reward's level at the test location.
     *
     * @return array<string, mixed>
     */
    private function level(Reward $reward): array
    {
        return app(StockLedger::class)->levels($reward->id)->get(StockLedger::key($reward->id, null, $this->location->id));
    }

    public function test_redeeming_spends_points_and_reserves_a_unit(): void
    {
        $reward = $this->reward(5, ['name' => 'Silk Krama']);

        $this->redeem($reward)
            ->assertCreated()
            ->assertJsonPath('data.status', Redemption::RESERVED)
            ->assertJsonPath('data.reward_name', 'Silk Krama')
            ->assertJsonPath('data.location_id', $this->location->id)
            ->assertJsonPath('data.points', 200);

        $this->assertSame(800, $this->customer->fresh()->points_balance);
        $this->assertDatabaseHas('loyalty_transactions', ['customer_id' => $this->customer->id, 'type' => LoyaltyTransaction::TYPE_SPEND, 'points' => -200]);
        $level = $this->level($reward);
        $this->assertSame(5, $level['on_hand']);
        $this->assertSame(1, $level['reserved']);
        $this->assertSame(4, $level['available']);
    }

    public function test_a_redemption_holds_for_the_configured_time(): void
    {
        Setting::put(Setting::RESERVATION_HOURS, 10);

        $this->redeem($this->reward())->assertCreated();

        $this->assertEqualsWithDelta(now()->addHours(10)->timestamp, Redemption::sole()->reserved_until->timestamp, 5);
    }

    public function test_rewards_that_cannot_be_redeemed_leave_points_alone(): void
    {
        $this->redeem($this->reward(5, ['is_active' => false]))->assertUnprocessable()->assertJsonPath('errors.redemption.0', 'Reward unavailable.');
        $this->redeem($this->reward(5, ['expires_at' => now()->subDay()]))->assertUnprocessable()->assertJsonPath('errors.redemption.0', 'Reward unavailable.');
        $this->redeem($this->reward(5, ['points_cost' => 5000]))->assertUnprocessable()->assertJsonPath('errors.redemption.0', 'Insufficient points.');
        $this->redeem($this->reward(0))->assertUnprocessable()->assertJsonPath('errors.redemption.0', 'Reward is out of stock.');

        $gold = Tier::factory()->create(['name' => 'Gold', 'min_points' => 5000]);
        $this->redeem($this->reward(5, ['min_tier_id' => $gold->id]))->assertUnprocessable()->assertJsonPath('errors.redemption.0', 'Requires Gold tier.');

        $this->assertSame(1000, $this->customer->fresh()->points_balance);
        $this->assertDatabaseCount('redemptions', 0);
    }

    public function test_a_reward_can_only_be_open_once_per_customer(): void
    {
        $reward = $this->reward();
        $this->redeem($reward)->assertCreated();

        $this->redeem($reward)->assertUnprocessable()->assertJsonPath('errors.redemption.0', 'You already redeemed this reward.');
        $this->assertSame(800, $this->customer->fresh()->points_balance);
    }

    public function test_rewards_with_options_need_one_chosen(): void
    {
        $reward = $this->reward(0);
        $variant = RewardVariant::factory()->for($reward)->create(['size' => 'Large', 'color' => 'Red']);
        $this->receive($reward, 2, $variant->id);

        $this->redeem($reward)->assertUnprocessable()->assertJsonPath('errors.redemption.0', 'Choose an option for this reward.');
        $this->redeem($reward, $variant->id)->assertCreated()->assertJsonPath('data.reward_name', "{$reward->name} (Large / Red)");
    }

    public function test_customers_can_cancel_their_own_redemption_for_a_refund(): void
    {
        $reward = $this->reward();
        $id = $this->redeem($reward)->json('data.id');

        $this->postJson("/api/redemptions/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', Redemption::CANCELLED)
            ->assertJsonPath('data.close_reason', 'Cancelled by customer');

        $this->assertSame(1000, $this->customer->fresh()->points_balance);
        $this->assertDatabaseHas('loyalty_transactions', ['type' => LoyaltyTransaction::TYPE_REFUND, 'points' => 200]);
        $this->assertSame(0, $this->level($reward)['reserved']);

        $this->postJson("/api/redemptions/{$id}/cancel")->assertUnprocessable();
        $this->assertSame(1000, $this->customer->fresh()->points_balance);
    }

    public function test_customers_cannot_touch_another_customers_redemption(): void
    {
        $id = $this->redeem($this->reward())->json('data.id');

        Sanctum::actingAs($this->customerWith(0), guard: 'customer');

        $this->postJson("/api/redemptions/{$id}/cancel")->assertNotFound();
        $this->postJson('/api/redemptions/abc/cancel')->assertNotFound();
        $this->assertSame(Redemption::RESERVED, Redemption::sole()->status);
    }

    public function test_staff_fulfilment_takes_the_reserved_unit_out_of_stock(): void
    {
        $reward = $this->reward();
        $id = $this->redeem($reward)->json('data.id');
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/backend/redemptions/{$id}/fulfill", ['method' => Redemption::SHIPPED])
            ->assertOk()
            ->assertJsonPath('data.status', Redemption::FULFILLED)
            ->assertJsonPath('data.fulfillment', Redemption::SHIPPED);

        $level = $this->level($reward);
        $this->assertSame(4, $level['on_hand']);
        $this->assertSame(0, $level['reserved']);

        $this->postJson("/api/backend/redemptions/{$id}/fulfill", ['method' => Redemption::SHIPPED])->assertUnprocessable();
        $this->assertSame(4, $this->level($reward)['on_hand']);
    }

    /**
     * Fulfil a redemption of the reward, returning its id.
     */
    private function fulfilled(Reward $reward): int
    {
        $id = $this->redeem($reward)->json('data.id');
        Sanctum::actingAs($this->staff);
        $this->postJson("/api/backend/redemptions/{$id}/fulfill", ['method' => Redemption::SHIPPED])->assertOk();

        return $id;
    }

    /**
     * Return a redemption through the API.
     *
     * @param  array<string, mixed>  $fields
     */
    private function returnRedemption(Reward $reward, int $id, array $fields = []): TestResponse
    {
        return $this->postJson('/api/backend/stock-movements', [
            'type' => 'return',
            'reward_id' => $reward->id,
            'location_id' => $this->location->id,
            'quantity' => 1,
            'redemption_id' => $id,
            'reason' => 'Wrong size',
            ...$fields,
        ]);
    }

    public function test_a_returned_redemption_goes_back_into_stock_with_a_refund(): void
    {
        $reward = $this->reward();
        $id = $this->fulfilled($reward);
        $this->assertSame(4, $this->level($reward)['on_hand']);

        $this->returnRedemption($reward, $id, ['refund_points' => true])
            ->assertCreated()
            ->assertJsonPath('data.0.type', 'RETURN')
            ->assertJsonPath('data.0.reference', Redemption::sole()->reference());

        $redemption = Redemption::sole();
        $this->assertSame(Redemption::RETURNED, $redemption->status);
        $this->assertSame('Wrong size', $redemption->close_reason);
        $this->assertSame(5, $this->level($reward)['on_hand']);
        $this->assertSame(1000, $this->customer->fresh()->points_balance);
    }

    public function test_a_return_keeps_the_points_spent_unless_they_are_refunded(): void
    {
        $reward = $this->reward();

        $this->returnRedemption($reward, $this->fulfilled($reward))->assertCreated();

        $this->assertSame(Redemption::RETURNED, Redemption::sole()->status);
        $this->assertSame(800, $this->customer->fresh()->points_balance);
    }

    public function test_a_redemption_can_only_be_returned_once_and_only_after_fulfilment(): void
    {
        $reward = $this->reward();
        $id = $this->fulfilled($reward);
        $this->returnRedemption($reward, $id, ['refund_points' => true])->assertCreated();

        $this->returnRedemption($reward, $id, ['refund_points' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.redemption.0', 'Only fulfilled redemptions can be returned.');

        $this->assertSame(1000, $this->customer->fresh()->points_balance);
        $this->assertSame(5, $this->level($reward)['on_hand']);

        Sanctum::actingAs($this->customerWith(500), guard: 'customer');
        $open = $this->redeem($this->reward())->json('data.id');
        Sanctum::actingAs($this->staff);
        $this->returnRedemption($reward, $open)->assertUnprocessable();
    }

    public function test_clerks_can_return_stock_but_not_refund_points(): void
    {
        $reward = $this->reward();
        $id = $this->fulfilled($reward);
        $role = Role::factory()->withPages(['stock'])->create(['stock_authority' => 'clerk']);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->returnRedemption($reward, $id, ['refund_points' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.redemption.0', 'Refunding points needs stock manager authority.');
        $this->assertSame(Redemption::FULFILLED, Redemption::sole()->status);
        $this->assertSame(4, $this->level($reward)['on_hand']);

        $this->returnRedemption($reward, $id)->assertCreated();
        $this->assertSame(Redemption::RETURNED, Redemption::sole()->status);
    }

    public function test_a_serial_goes_back_in_with_the_return(): void
    {
        $reward = RewardStockItem::factory()->serialTracked()->create()->reward;
        $reward->update(['min_tier_id' => $this->tier->id, 'points_cost' => 200]);
        app(StockLedger::class)->receive($this->staff, [
            'reward_id' => $reward->id, 'location_id' => $this->location->id, 'quantity' => 1,
            'source' => 'DONATION', 'unit_cost' => 0, 'serials' => ['KR-001'],
        ]);

        $this->returnRedemption($reward, $this->fulfilled($reward))->assertCreated();

        $this->assertSame(['KR-001'], $this->level($reward)['serials']);
    }

    public function test_a_returned_unit_fills_a_waiting_backorder(): void
    {
        $reward = RewardStockItem::factory()->create(['allow_backorder' => true])->reward;
        $reward->update(['min_tier_id' => $this->tier->id, 'points_cost' => 200]);
        $this->receive($reward, 1);
        $id = $this->fulfilled($reward);
        Sanctum::actingAs($waiting = $this->customerWith(500), guard: 'customer');
        $this->redeem($reward)->assertCreated()->assertJsonPath('data.status', Redemption::BACKORDERED);

        Sanctum::actingAs($this->staff);
        $this->returnRedemption($reward, $id)->assertCreated();

        $this->assertSame(Redemption::RESERVED, $waiting->redemptions()->sole()->status);
    }

    public function test_a_serial_tracked_unit_is_picked_when_staff_name_none(): void
    {
        $reward = RewardStockItem::factory()->serialTracked()->create()->reward;
        $reward->update(['min_tier_id' => $this->tier->id, 'points_cost' => 200]);
        app(StockLedger::class)->receive($this->staff, [
            'reward_id' => $reward->id, 'location_id' => $this->location->id, 'quantity' => 1,
            'source' => 'DONATION', 'unit_cost' => 0, 'serials' => ['kr-001'],
        ]);
        $id = $this->redeem($reward)->json('data.id');
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/backend/redemptions/{$id}/fulfill", ['method' => Redemption::PICKED_UP])
            ->assertOk()
            ->assertJsonPath('data.serial', 'KR-001');
    }

    public function test_staff_cancelling_needs_a_reason_and_manager_authority(): void
    {
        $id = $this->redeem($this->reward())->json('data.id');

        Sanctum::actingAs(User::factory()->withPages(['stock'])->create());
        $this->postJson("/api/backend/redemptions/{$id}/cancel", ['reason' => 'Damaged'])->assertForbidden();

        Sanctum::actingAs($this->staff);
        $this->postJson("/api/backend/redemptions/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/backend/redemptions/{$id}/cancel", ['reason' => 'Damaged'])
            ->assertOk()
            ->assertJsonPath('data.close_reason', 'Damaged');

        $this->assertSame(1000, $this->customer->fresh()->points_balance);
    }

    public function test_clerks_can_fulfil_and_extend(): void
    {
        $id = $this->redeem($this->reward())->json('data.id');
        $until = Redemption::sole()->reserved_until;
        $role = Role::factory()->withPages(['stock'])->create(['stock_authority' => 'clerk']);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));

        $this->postJson("/api/backend/redemptions/{$id}/extend", ['hours' => 200])->assertUnprocessable();
        $this->postJson("/api/backend/redemptions/{$id}/extend", ['hours' => 24])->assertOk();
        $this->assertEqualsWithDelta($until->addHours(24)->timestamp, Redemption::sole()->reserved_until->timestamp, 5);

        $this->postJson("/api/backend/redemptions/{$id}/fulfill", ['method' => Redemption::PICKED_UP])->assertOk();
    }

    public function test_overdue_reservations_expire_and_refund(): void
    {
        $reward = $this->reward();
        $this->redeem($reward)->assertCreated();

        $this->travel(73)->hours();
        $this->artisan('redemptions:expire')->assertSuccessful();

        $redemption = Redemption::sole();
        $this->assertSame(Redemption::EXPIRED, $redemption->status);
        $this->assertSame('Reservation timed out', $redemption->close_reason);
        $this->assertSame(1000, $this->customer->fresh()->points_balance);
        $this->assertSame(0, $this->level($reward)['reserved']);
    }

    public function test_reading_the_account_expires_what_the_schedule_missed(): void
    {
        $this->redeem($this->reward())->assertCreated();
        $this->travel(73)->hours();

        $this->getJson('/api/account')->assertOk()->assertJsonPath('data.redemptions.0.status', Redemption::EXPIRED);
    }

    public function test_sold_out_rewards_can_be_backordered_and_received_stock_fills_them_oldest_first(): void
    {
        $reward = RewardStockItem::factory()->create(['allow_backorder' => true])->reward;
        $reward->update(['min_tier_id' => $this->tier->id, 'points_cost' => 200]);

        $this->redeem($reward)->assertCreated()->assertJsonPath('data.status', Redemption::BACKORDERED);
        Sanctum::actingAs($second = $this->customerWith(500), guard: 'customer');
        $this->redeem($reward)->assertCreated();
        $this->assertSame(800, $this->customer->fresh()->points_balance);

        Sanctum::actingAs($this->staff);
        $this->postJson('/api/backend/stock-movements', [
            'type' => 'receive', 'reward_id' => $reward->id, 'location_id' => $this->location->id,
            'quantity' => 1, 'source' => 'PURCHASE', 'unit_cost' => 1,
        ])->assertCreated();

        $this->assertSame(Redemption::RESERVED, Redemption::query()->where('customer_id', $this->customer->id)->sole()->status);
        $this->assertSame(Redemption::BACKORDERED, Redemption::query()->where('customer_id', $second->id)->sole()->status);
        $this->assertSame(1, $this->level($reward)['reserved']);
    }

    public function test_restock_requests_are_flagged_when_stock_arrives(): void
    {
        $reward = $this->reward(0);

        $this->postJson('/api/restock-requests', ['reward_id' => $reward->id])->assertCreated();
        $this->postJson('/api/restock-requests', ['reward_id' => $reward->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reward_id.0', 'You will already be notified when this is back in stock.');

        Sanctum::actingAs($this->staff);
        $this->postJson('/api/backend/stock-movements', [
            'type' => 'receive', 'reward_id' => $reward->id, 'location_id' => $this->location->id,
            'quantity' => 1, 'source' => 'PURCHASE', 'unit_cost' => 1,
        ])->assertCreated();

        $this->assertNotNull(RestockRequest::sole()->notified_at);
    }

    public function test_customers_only_remove_their_own_restock_requests(): void
    {
        $id = $this->postJson('/api/restock-requests', ['reward_id' => $this->reward(0)->id])->json('data.id');

        Sanctum::actingAs($this->customerWith(0), guard: 'customer');
        $this->deleteJson("/api/restock-requests/{$id}")->assertNotFound();

        Sanctum::actingAs($this->customer, guard: 'customer');
        $this->deleteJson("/api/restock-requests/{$id}")->assertOk();
        $this->assertDatabaseCount('restock_requests', 0);
    }

    public function test_the_stock_snapshot_lists_redemptions_for_fulfilment(): void
    {
        $this->redeem($this->reward(5, ['name' => 'Silk Krama']))->assertCreated();
        Sanctum::actingAs($this->staff);

        $this->getJson('/api/backend/stock')
            ->assertOk()
            ->assertJsonPath('data.redemptions.0.reward_name', 'Silk Krama')
            ->assertJsonPath('data.redemptions.0.status', Redemption::RESERVED)
            ->assertJsonPath('data.reservation_hours', 72);
    }
}
