<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_can_change_the_exchange_rate(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->putJson('/api/backend/settings/exchange-rate', ['rate' => 4150])->assertOk();

        $this->assertSame(4150, Setting::integer(Setting::EXCHANGE_RATE));
        $this->assertDatabaseHas('activity_logs', ['event' => 'EXCHANGE_RATE_UPDATED']);
        $this->getJson('/api/storefront')->assertJsonPath('data.exchange_rate', 4150);
    }

    public function test_the_exchange_rate_is_validated_and_kept_from_other_staff(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->putJson('/api/backend/settings/exchange-rate', ['rate' => 0])->assertUnprocessable();
        $this->putJson('/api/backend/settings/exchange-rate', ['rate' => 40.5])->assertUnprocessable();

        Sanctum::actingAs(User::factory()->withPages(['exchange-rate'])->create());
        $this->putJson('/api/backend/settings/exchange-rate', ['rate' => 4150])->assertForbidden();

        $this->assertSame(4000, Setting::integer(Setting::EXCHANGE_RATE));
    }

    public function test_only_a_stock_admin_changes_the_reservation_time(): void
    {
        $role = Role::factory()->withPages(['stock'])->create(['stock_authority' => 'manager']);
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id]));
        $this->putJson('/api/backend/stock-settings', ['reservation_hours' => 24])->assertForbidden();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->putJson('/api/backend/stock-settings', ['reservation_hours' => 1000])->assertUnprocessable();
        $this->putJson('/api/backend/stock-settings', ['reservation_hours' => 24])->assertOk();

        $this->assertSame(24, Setting::integer(Setting::RESERVATION_HOURS));
    }

    public function test_dashboards_get_totals_without_reading_the_ledger(): void
    {
        $customer = Customer::factory()->create();
        $customer->recordPoints(LoyaltyTransaction::TYPE_EARN, 500);
        $customer->recordPoints(LoyaltyTransaction::TYPE_SPEND, -200);
        Sanctum::actingAs(User::factory()->withPages(['dashboard'])->create());

        $this->getJson('/api/backend/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.points_issued', 500)
            ->assertJsonPath('data.points_redeemed', 200)
            ->assertJsonPath('data.rewards_redeemed', 0);
        $this->getJson('/api/backend/points/transactions')->assertForbidden();

        Sanctum::actingAs(User::factory()->withPages(['products'])->create());
        $this->getJson('/api/backend/reports/summary')->assertForbidden();
    }

    public function test_staff_can_update_their_own_profile(): void
    {
        $staff = User::factory()->superAdmin()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($staff);

        $this->patchJson('/api/backend/profile', ['name' => 'Dara', 'email' => $other->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->patchJson('/api/backend/profile', ['name' => 'Dara', 'email' => 'dara@example.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Dara')
            ->assertJsonPath('data.email', 'dara@example.com');
    }
}
