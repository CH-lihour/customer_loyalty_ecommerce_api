<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PointTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->superAdmin()->create();
        Sanctum::actingAs($this->admin);
    }

    public function test_transactions_list_newest_first_with_the_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'Sokha Meas', 'email' => 'sokha@example.com']);
        $older = $customer->recordPoints(LoyaltyTransaction::TYPE_EARN, 100, 'Order');
        $older->forceFill(['created_at' => now()->subWeek()])->save();
        $customer->recordPoints(LoyaltyTransaction::TYPE_SPEND, -40, 'Voucher');

        $this->getJson('/api/backend/points/transactions')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', LoyaltyTransaction::TYPE_SPEND)
            ->assertJsonPath('data.0.points', -40)
            ->assertJsonPath('data.0.balance_before', 100)
            ->assertJsonPath('data.0.balance_after', 60)
            ->assertJsonPath('data.0.customer.name', 'Sokha Meas')
            ->assertJsonPath('data.0.customer.email', 'sokha@example.com')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_transactions_can_be_filtered(): void
    {
        $sokha = Customer::factory()->create(['name' => 'Sokha Meas', 'email' => 'sokha@example.com']);
        $vannak = Customer::factory()->create(['name' => 'Vannak Chea', 'email' => 'vannak@example.com']);
        $sokha->recordPoints(LoyaltyTransaction::TYPE_EARN, 100, 'Order');
        $sokha->recordPoints(LoyaltyTransaction::TYPE_SPEND, -40, 'Voucher');
        $vannak->recordPoints(LoyaltyTransaction::TYPE_REFERRAL, 50, 'Friend')
            ->forceFill(['created_at' => '2026-01-15 10:00:00'])->save();

        $count = fn (string $query) => $this->getJson("/api/backend/points/transactions?{$query}")->assertOk()->json('meta.total');

        $this->assertSame(2, $count('search=SOKHA'));
        $this->assertSame(1, $count('search=vannak@example'));
        $this->assertSame(1, $count("search={$vannak->id}"));
        $this->assertSame(1, $count('type=REFERRAL'));
        $this->assertSame(2, $count('direction=earn'));
        $this->assertSame(1, $count('direction=deduct'));
        $this->assertSame(1, $count('date_from=2026-01-15&date_to=2026-01-15'));
        $this->assertSame(0, $count('type=ADJUST'));

        $this->getJson('/api/backend/points/transactions?type=BONUS&direction=up')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'direction']);
    }

    public function test_customer_picker_searches_and_shows_balance_and_tier(): void
    {
        $customer = Customer::factory()->create(['name' => 'Sokha Meas']);
        Customer::factory()->create(['name' => 'Vannak Chea']);
        $customer->recordPoints(LoyaltyTransaction::TYPE_EARN, 75, 'Order');

        $this->getJson('/api/backend/points/customers?search=sokha')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $customer->id)
            ->assertJsonPath('data.0.points_balance', 75)
            ->assertJsonPath('data.0.tier.name', $customer->tier->name)
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_admin_can_add_points_and_it_is_recorded(): void
    {
        $customer = Customer::factory()->create();

        $this->postJson('/api/backend/points/adjustments', [
            'customer_id' => $customer->id,
            'points' => 200,
            'reason' => ' Goodwill gesture ',
        ])->assertCreated()
            ->assertJsonPath('data.type', LoyaltyTransaction::TYPE_ADJUST)
            ->assertJsonPath('data.balance_before', 0)
            ->assertJsonPath('data.balance_after', 200)
            ->assertJsonPath('data.reason', 'Manual adjustment: Goodwill gesture')
            ->assertJsonPath('data.creator.id', $this->admin->id);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'points_balance' => 200]);
        $this->assertDatabaseHas('activity_logs', [
            'customer_id' => $customer->id,
            'user_id' => $this->admin->id,
            'event' => 'POINTS_ADJUSTED',
        ]);
    }

    public function test_admin_can_deduct_points_without_changing_tier_progress(): void
    {
        $customer = Customer::factory()->create();
        $customer->recordPoints(LoyaltyTransaction::TYPE_EARN, 300, 'Order');

        $this->postJson('/api/backend/points/adjustments', [
            'customer_id' => $customer->id,
            'points' => -120,
            'reason' => 'Order returned',
        ])->assertCreated()
            ->assertJsonPath('data.points', -120)
            ->assertJsonPath('data.balance_after', 180);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'points_balance' => 180, 'qualifying_points' => 300]);
    }

    public function test_deduction_cannot_make_the_balance_negative(): void
    {
        $customer = Customer::factory()->create(['name' => 'Sokha Meas']);
        $customer->recordPoints(LoyaltyTransaction::TYPE_EARN, 50, 'Order');

        $this->postJson('/api/backend/points/adjustments', [
            'customer_id' => $customer->id,
            'points' => -51,
            'reason' => 'Too much',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['points' => 'Sokha Meas only has 50 points']);

        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'points_balance' => 50]);
        $this->assertDatabaseCount('loyalty_transactions', 1);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_adjustment_fields_are_validated(): void
    {
        $this->postJson('/api/backend/points/adjustments', ['customer_id' => 999, 'points' => 0, 'reason' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'points' => 'cannot be zero', 'reason' => 'Enter a reason']);

        $this->postJson('/api/backend/points/adjustments', ['customer_id' => 1, 'points' => 1.5, 'reason' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['points', 'reason']);
    }

    public function test_seeded_ledger_chains_to_each_customers_balance(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seeded = LoyaltyTransaction::count();

        $this->assertGreaterThan(0, $seeded);

        Customer::whereHas('loyaltyTransactions')->get()->each(function (Customer $customer) {
            $balance = 0;

            foreach ($customer->loyaltyTransactions()->orderBy('created_at')->orderBy('id')->get() as $row) {
                $this->assertSame($balance, $row->balance_before);
                $this->assertSame($row->balance_before + $row->points, $row->balance_after);
                $this->assertContains($row->type, LoyaltyTransaction::TYPES);
                $balance = $row->balance_after;
            }

            $this->assertSame($balance, $customer->points_balance);
        });

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('loyalty_transactions', $seeded);
    }

    public function test_the_ledger_cannot_be_changed_through_the_api(): void
    {
        $row = LoyaltyTransaction::factory()->create();

        $this->patchJson("/api/backend/points/transactions/{$row->id}", ['points' => 1])->assertNotFound();
        $this->deleteJson("/api/backend/points/transactions/{$row->id}")->assertNotFound();
    }

    public function test_points_need_the_points_page(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs(User::factory()->withPages(['dashboard'])->create());

        $this->getJson('/api/backend/points/transactions')->assertForbidden();
        $this->getJson('/api/backend/points/customers')->assertForbidden();
        $this->postJson('/api/backend/points/adjustments', ['customer_id' => $customer->id, 'points' => 5, 'reason' => 'No'])->assertForbidden();

        Sanctum::actingAs(User::factory()->withPages(['points'])->create());

        $this->getJson('/api/backend/points/transactions')->assertOk();
    }

    public function test_customer_sees_only_their_own_history(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $customer->recordPoints(LoyaltyTransaction::TYPE_EARN, 100, 'Mine');
        $other->recordPoints(LoyaltyTransaction::TYPE_EARN, 900, 'Theirs');

        Sanctum::actingAs($customer, guard: 'customer');

        $this->getJson('/api/points')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Mine');
    }

    public function test_customer_history_needs_a_customer_login(): void
    {
        $this->getJson('/api/points')->assertUnauthorized();
    }
}
