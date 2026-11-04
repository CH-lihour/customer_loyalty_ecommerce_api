<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Referral;
use App\Models\Tier;
use App\Models\TierHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_account_holds_only_the_customers_own_records(): void
    {
        $bronze = Tier::factory()->create(['name' => 'Bronze']);
        $silver = Tier::factory()->create(['name' => 'Silver']);
        $customer = Customer::factory()->for($silver)->create(['name' => 'Sokha']);
        $stranger = Customer::factory()->for($bronze)->create();
        $badge = Badge::factory()->create(['code' => 'FIRST']);
        $customer->badges()->attach($badge->id);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'status' => Order::COMPLETED]);
        Order::factory()->create(['customer_id' => $stranger->id]);
        Referral::factory()->create(['referrer_id' => $customer->id, 'referred_id' => $stranger->id]);
        TierHistory::query()->create(['customer_id' => $customer->id, 'from_tier_id' => $bronze->id, 'to_tier_id' => $silver->id, 'reason' => 'Promoted']);
        Sanctum::actingAs($customer, guard: 'customer');

        $this->getJson('/api/account')
            ->assertOk()
            ->assertJsonPath('data.profile.name', 'Sokha')
            ->assertJsonPath('data.profile.tier', 'Silver')
            ->assertJsonPath('data.profile.badges', ['FIRST'])
            ->assertJsonPath('data.profile.orders_count', 1)
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.id', $order->id)
            ->assertJsonCount(1, 'data.referrals')
            ->assertJsonPath('data.referrals.0.referred_name', $stranger->name)
            ->assertJsonPath('data.tier_history.0.from', 'Bronze')
            ->assertJsonPath('data.tier_history.0.to', 'Silver');
    }

    public function test_customers_can_update_their_details_but_not_their_points(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer, guard: 'customer');

        $this->patchJson('/api/profile', ['name' => 'Dara', 'phone' => '099 111 222', 'province' => 'Kampot', 'points_balance' => 9999])
            ->assertOk()
            ->assertJsonPath('data.name', 'Dara')
            ->assertJsonPath('data.province', 'Kampot');

        $this->assertSame(0, $customer->fresh()->points_balance);
        $this->patchJson('/api/profile', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_the_account_needs_a_customer_token(): void
    {
        $this->getJson('/api/account')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/account')->assertUnauthorized();
    }
}
