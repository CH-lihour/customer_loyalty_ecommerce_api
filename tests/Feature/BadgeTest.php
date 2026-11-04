<?php

namespace Tests\Feature;

use App\Models\Badge;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
    }

    public function test_index_lists_badges_with_how_many_customers_earned_them(): void
    {
        $firstPurchase = Badge::factory()->create(['code' => 'FIRST_PURCHASE']);
        Badge::factory()->create(['code' => 'BIG_SPENDER']);
        $firstPurchase->customers()->attach(Customer::factory()->count(2)->create());

        $this->getJson('/api/backend/badges')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', 'FIRST_PURCHASE')
            ->assertJsonPath('data.0.customers_count', 2)
            ->assertJsonPath('data.1.customers_count', 0)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_badge_can_be_created(): void
    {
        $this->postJson('/api/backend/badges', [
            'code' => 'WEEKEND_SHOPPER',
            'name' => ' Weekend Shopper ',
            'name_kh' => '',
            'description' => 'Complete 3 orders',
            'icon' => 'trophy',
            'color' => '#10B981',
            'metric' => 'orders',
            'threshold' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Weekend Shopper')
            ->assertJsonPath('data.name_kh', null)
            ->assertJsonPath('data.customers_count', 0);

        $this->assertDatabaseHas('badges', ['code' => 'WEEKEND_SHOPPER', 'metric' => 'orders', 'threshold' => 3]);
    }

    public function test_badge_can_be_updated_but_its_code_cannot_change(): void
    {
        $badge = Badge::factory()->create(['code' => 'BIG_SPENDER', 'metric' => 'spend', 'threshold' => 500]);

        $this->patchJson("/api/backend/badges/{$badge->id}", ['threshold' => 750, 'icon' => 'gem'])
            ->assertOk()
            ->assertJsonPath('data.threshold', 750)
            ->assertJsonPath('data.metric', 'spend');

        $this->patchJson("/api/backend/badges/{$badge->id}", ['code' => 'HUGE_SPENDER'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'cannot be changed']);

        $this->assertDatabaseHas('badges', ['id' => $badge->id, 'code' => 'BIG_SPENDER', 'threshold' => 750]);
    }

    public function test_badge_fields_are_validated(): void
    {
        Badge::factory()->create(['code' => 'FIRST_PURCHASE']);

        $this->postJson('/api/backend/badges', [
            'code' => 'FIRST_PURCHASE',
            'icon' => 'dragon',
            'color' => 'gold',
            'metric' => 'logins',
            'threshold' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'already exists', 'name', 'icon', 'color', 'metric', 'threshold']);

        $this->postJson('/api/backend/badges', ['code' => 'first purchase!', 'name' => 'First', 'metric' => 'orders', 'threshold' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'capital letters']);
    }

    public function test_deleting_a_badge_takes_it_from_customers_who_earned_it(): void
    {
        $badge = Badge::factory()->create();
        $customer = Customer::factory()->create();
        $badge->customers()->attach($customer);

        $this->deleteJson("/api/backend/badges/{$badge->id}")->assertOk();

        $this->assertModelMissing($badge);
        $this->assertModelExists($customer);
        $this->assertDatabaseCount('customer_badges', 0);
    }

    public function test_badges_need_the_badges_page(): void
    {
        $badge = Badge::factory()->create();
        Sanctum::actingAs(User::factory()->withPages(['dashboard'])->create());

        $this->getJson('/api/backend/badges')->assertForbidden();
        $this->deleteJson("/api/backend/badges/{$badge->id}")->assertForbidden();

        Sanctum::actingAs(User::factory()->withPages(['badges'])->create());

        $this->getJson('/api/backend/badges')->assertOk();
    }
}
