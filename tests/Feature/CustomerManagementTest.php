<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Referral;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private Tier $bronze;

    private Tier $gold;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->bronze = Tier::factory()->create(['name' => 'Bronze', 'min_points' => 0]);
        $this->gold = Tier::factory()->create(['name' => 'Gold', 'min_points' => 5000]);
    }

    public function test_customers_are_listed_with_their_totals_and_no_password(): void
    {
        $customer = Customer::factory()->for($this->bronze)->create(['name' => 'Sokha']);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => Order::COMPLETED]);
        Order::factory()->create(['customer_id' => $customer->id, 'status' => Order::PENDING]);
        Customer::factory()->for($this->bronze)->create(['name' => 'Dara']);

        $response = $this->getJson('/api/backend/customers?search=sok')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Sokha')
            ->assertJsonPath('data.0.tier', 'Bronze')
            ->assertJsonPath('data.0.orders_count', 1);

        $this->assertArrayNotHasKey('password', $response->json('data.0'));
        $this->assertNotNull($response->json('data.0.last_order_at'));
    }

    public function test_a_tier_can_be_locked_and_unlocked_with_a_reason(): void
    {
        $customer = Customer::factory()->for($this->bronze)->create();

        $this->patchJson("/api/backend/customers/{$customer->id}/tier", ['tier' => 'Gold'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->patchJson("/api/backend/customers/{$customer->id}/tier", ['tier' => 'Gold', 'reason' => 'VIP'])
            ->assertOk()
            ->assertJsonPath('data.tier', 'Gold')
            ->assertJsonPath('data.tier_locked', true);
        $this->assertDatabaseHas('tier_histories', ['customer_id' => $customer->id, 'to_tier_id' => $this->gold->id, 'reason' => 'VIP']);

        $this->patchJson("/api/backend/customers/{$customer->id}/tier", ['tier' => null, 'reason' => 'Back to points'])
            ->assertOk()
            ->assertJsonPath('data.tier', 'Bronze')
            ->assertJsonPath('data.tier_locked', false);
    }

    public function test_deactivating_a_customer_signs_them_out(): void
    {
        $customer = Customer::factory()->for($this->bronze)->create();
        $customer->createToken('web');

        $this->patchJson("/api/backend/customers/{$customer->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/login', ['email' => $customer->email, 'password' => 'password'])->assertUnprocessable();
    }

    public function test_only_super_admins_change_customer_status(): void
    {
        $customer = Customer::factory()->for($this->bronze)->create();
        Sanctum::actingAs(User::factory()->withPages(['customers'])->create());

        $this->patchJson("/api/backend/customers/{$customer->id}/status", ['is_active' => false])->assertForbidden();
        $this->getJson('/api/backend/customers')->assertOk();
    }

    public function test_customers_need_a_page_that_uses_them(): void
    {
        $customer = Customer::factory()->for($this->bronze)->create();
        Sanctum::actingAs(User::factory()->withPages(['products'])->create());

        $this->getJson('/api/backend/customers')->assertForbidden();
        $this->patchJson("/api/backend/customers/{$customer->id}/tier", ['tier' => 'Gold', 'reason' => 'VIP'])->assertForbidden();
    }

    public function test_referrals_are_listed_with_both_customers(): void
    {
        $referrer = Customer::factory()->for($this->bronze)->create(['name' => 'Sokha']);
        $referred = Customer::factory()->for($this->bronze)->create(['name' => 'Dara']);
        Referral::factory()->create(['referrer_id' => $referrer->id, 'referred_id' => $referred->id]);

        $this->getJson('/api/backend/referrals')
            ->assertOk()
            ->assertJsonPath('data.0.referrer_name', 'Sokha')
            ->assertJsonPath('data.0.referred_name', 'Dara')
            ->assertJsonPath('data.0.completed', false);

        Sanctum::actingAs(User::factory()->withPages(['products'])->create());
        $this->getJson('/api/backend/referrals')->assertForbidden();
    }
}
