<?php

namespace Tests\Feature;

use App\Models\Reward;
use App\Models\Tier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RewardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Tier $silver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->superAdmin()->create();
        Sanctum::actingAs($this->admin);
        $this->silver = Tier::factory()->create(['name' => 'Silver']);
    }

    public function test_index_lists_rewards_with_their_minimum_tier(): void
    {
        Reward::factory()->for($this->silver, 'minTier')->create(['name' => 'Free Coffee', 'image' => 'https://example.com/coffee.jpg']);
        Reward::factory()->inactive()->create();

        $this->getJson('/api/backend/rewards')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Free Coffee')
            ->assertJsonPath('data.0.min_tier.name', 'Silver')
            ->assertJsonPath('data.0.image_url', 'https://example.com/coffee.jpg')
            ->assertJsonPath('data.0.is_active', true);

        $this->getJson('/api/backend/rewards?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false);
    }

    public function test_tier_options_list_tiers_from_the_lowest(): void
    {
        Tier::factory()->create(['name' => 'Gold', 'min_points' => 1000]);

        $this->getJson('/api/backend/rewards/tier-options')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Silver')
            ->assertJsonPath('data.1.name', 'Gold');
    }

    public function test_reward_can_be_created_with_an_uploaded_image(): void
    {
        Storage::fake('public');

        $response = $this->post('/api/backend/rewards', [
            'min_tier_id' => $this->silver->id,
            'name' => ' Free Shipping ',
            'category' => 'Shipping',
            'points_cost' => 150,
            'expires_at' => now()->addMonth()->toDateString(),
            'image_file' => UploadedFile::fake()->image('shipping.png'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Free Shipping')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.min_tier.name', 'Silver');

        $reward = Reward::sole();
        Storage::disk('public')->assertExists($reward->image);
        $this->assertSame(url('storage/'.$reward->image), $response->json('data.image_url'));
    }

    public function test_reward_can_be_updated_and_a_new_image_replaces_the_old_file(): void
    {
        Storage::fake('public');
        $reward = Reward::factory()->create(['image' => 'rewards/old.png', 'points_cost' => 100]);
        Storage::disk('public')->put('rewards/old.png', 'old');

        $this->patch("/api/backend/rewards/{$reward->id}", [
            'points_cost' => 250,
            'image_file' => UploadedFile::fake()->image('new.png'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.points_cost', 250);

        Storage::disk('public')->assertMissing('rewards/old.png');
        Storage::disk('public')->assertExists($reward->refresh()->image);
    }

    public function test_reward_can_be_deactivated_and_reactivated(): void
    {
        $reward = Reward::factory()->create();

        $this->patchJson("/api/backend/rewards/{$reward->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('message', 'Reward deactivated')
            ->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/backend/rewards/{$reward->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->patchJson("/api/backend/rewards/{$reward->id}/status", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');
    }

    public function test_reward_fields_are_validated(): void
    {
        $this->postJson('/api/backend/rewards', [
            'min_tier_id' => 999,
            'points_cost' => 0,
            'stock' => -1,
            'image' => 'not a url',
            'expires_at' => now()->subDay()->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['min_tier_id', 'name', 'points_cost', 'stock', 'image', 'expires_at' => 'cannot be in the past']);
    }

    public function test_an_expired_reward_can_be_edited_without_moving_its_date(): void
    {
        $reward = Reward::factory()->create(['expires_at' => now()->subWeek()]);

        $this->patchJson("/api/backend/rewards/{$reward->id}", [
            'name' => 'Renamed',
            'expires_at' => $reward->expires_at->format('Y-m-d'),
        ])->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_unused_reward_can_be_deleted(): void
    {
        $reward = Reward::factory()->create();

        $this->deleteJson("/api/backend/rewards/{$reward->id}")->assertOk();

        $this->assertModelMissing($reward);
    }

    public function test_reward_with_stock_history_cannot_be_deleted(): void
    {
        $reward = Reward::factory()->create(['name' => 'Silk Krama']);
        $locationId = DB::table('stock_locations')->insertGetId(['name' => 'Main', 'type' => 'warehouse']);
        DB::table('reward_stock_movements')->insert([
            'reward_id' => $reward->id,
            'location_id' => $locationId,
            'actor_id' => $this->admin->id,
            'type' => 'RECEIVE',
            'on_hand_delta' => 5,
        ]);

        $this->deleteJson("/api/backend/rewards/{$reward->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Silk Krama has redemptions or stock history, so it cannot be deleted. Deactivate it instead.');

        $this->assertModelExists($reward);
    }

    public function test_rewards_need_the_rewards_page(): void
    {
        $reward = Reward::factory()->create();
        Sanctum::actingAs(User::factory()->withPages(['dashboard'])->create());

        $this->getJson('/api/backend/rewards')->assertForbidden();
        $this->getJson('/api/backend/rewards/tier-options')->assertForbidden();
        $this->patchJson("/api/backend/rewards/{$reward->id}/status", ['is_active' => false])->assertForbidden();

        Sanctum::actingAs(User::factory()->withPages(['rewards'])->create());

        $this->getJson('/api/backend/rewards')->assertOk();
    }
}
