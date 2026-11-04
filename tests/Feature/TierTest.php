<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TierTest extends TestCase
{
    use RefreshDatabase;

    private Tier $silver;

    private Tier $gold;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->silver = Tier::factory()->create(['name' => 'Silver', 'min_points' => 0, 'max_points' => 999, 'multiplier' => 1]);
        $this->gold = Tier::factory()->create(['name' => 'Gold', 'min_points' => 1000, 'max_points' => null, 'multiplier' => 1.5]);
    }

    public function test_index_lists_tiers_from_the_lowest_with_customer_counts(): void
    {
        Customer::factory()->count(2)->for($this->gold)->create();

        $this->getJson('/api/backend/tiers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Silver')
            ->assertJsonPath('data.1.name', 'Gold')
            ->assertJsonPath('data.1.customers_count', 2)
            ->assertJsonPath('data.1.multiplier', '1.50')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_tier_can_be_created(): void
    {
        $this->gold->update(['max_points' => 4999]);

        $this->postJson('/api/backend/tiers', [
            'name' => ' Platinum ',
            'min_points' => 5000,
            'max_points' => '',
            'multiplier' => 2,
            'color' => '#E5E4E2',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Platinum')
            ->assertJsonPath('data.max_points', null)
            ->assertJsonPath('data.customers_count', 0);

        $this->assertDatabaseHas('tiers', ['name' => 'Platinum', 'min_points' => 5000, 'max_points' => null]);
    }

    public function test_tier_can_be_updated_with_only_the_fields_sent(): void
    {
        $this->patchJson("/api/backend/tiers/{$this->gold->id}", ['multiplier' => 1.75, 'color' => '#FFD700'])
            ->assertOk()
            ->assertJsonPath('data.multiplier', '1.75')
            ->assertJsonPath('data.min_points', 1000);

        $this->patchJson("/api/backend/tiers/{$this->silver->id}", ['max_points' => 499])->assertOk();

        $this->assertDatabaseHas('tiers', ['id' => $this->silver->id, 'min_points' => 0, 'max_points' => 499]);
    }

    public function test_tier_fields_are_validated(): void
    {
        $this->postJson('/api/backend/tiers', ['name' => 'gold', 'min_points' => -1, 'multiplier' => 11, 'color' => 'gold'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'min_points', 'multiplier', 'color']);
    }

    public function test_max_points_must_be_above_min_points(): void
    {
        $this->postJson('/api/backend/tiers', ['name' => 'Bronze', 'min_points' => 500, 'max_points' => 500, 'multiplier' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_points' => 'greater than the minimum']);
    }

    public function test_tier_ranges_cannot_overlap(): void
    {
        $this->postJson('/api/backend/tiers', ['name' => 'Bronze', 'min_points' => 900, 'max_points' => 1100, 'multiplier' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['min_points' => 'overlaps the Silver tier']);

        $this->patchJson("/api/backend/tiers/{$this->silver->id}", ['max_points' => 1000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['min_points' => 'overlaps the Gold tier']);
    }

    public function test_only_one_tier_can_be_open_ended(): void
    {
        $this->patchJson("/api/backend/tiers/{$this->silver->id}", ['max_points' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_points' => 'Only one tier']);
    }

    public function test_unused_tier_can_be_deleted(): void
    {
        $this->deleteJson("/api/backend/tiers/{$this->gold->id}")->assertOk();

        $this->assertModelMissing($this->gold);
    }

    public function test_tier_in_use_cannot_be_deleted(): void
    {
        Customer::factory()->for($this->gold)->create();

        $this->deleteJson("/api/backend/tiers/{$this->gold->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Move the 1 customer in Gold to another tier first.');

        DB::table('rewards')->insert(['min_tier_id' => $this->silver->id, 'name' => 'Voucher', 'points_cost' => 100]);

        $this->deleteJson("/api/backend/tiers/{$this->silver->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Change the minimum tier of the 1 reward set to Silver first.');

        $this->assertModelExists($this->gold);
        $this->assertModelExists($this->silver);
    }

    public function test_last_tier_cannot_be_deleted(): void
    {
        $this->gold->delete();

        $this->deleteJson("/api/backend/tiers/{$this->silver->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Keep at least one tier: new customers join the lowest tier.');
    }

    public function test_tiers_need_the_tiers_page(): void
    {
        Sanctum::actingAs(User::factory()->withPages(['dashboard'])->create());

        $this->getJson('/api/backend/tiers')->assertForbidden();
        $this->deleteJson("/api/backend/tiers/{$this->gold->id}")->assertForbidden();

        Sanctum::actingAs(User::factory()->withPages(['tiers'])->create());

        $this->getJson('/api/backend/tiers')->assertOk();
    }
}
