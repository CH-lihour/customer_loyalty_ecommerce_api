<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Category;
use App\Models\Product;
use App\Models\Reward;
use App\Models\RewardStockItem;
use App\Models\RewardVariant;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\ProductStock;
use App\Services\StockLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_live_catalog_with_stock_but_no_costs(): void
    {
        $product = Product::factory()->create(['name' => 'Kampot Pepper', 'cost_price' => 3]);
        app(ProductStock::class)->set($product, 7);
        Product::factory()->for(Category::factory()->inactive())->create(['name' => 'Hidden']);

        $response = $this->getJson('/api/storefront')
            ->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.name', 'Kampot Pepper')
            ->assertJsonPath('data.products.0.stock', 7);

        $this->assertArrayNotHasKey('cost_price', $response->json('data.products.0'));
        $this->assertArrayNotHasKey('inventories', $response->json('data.products.0'));
    }

    public function test_rewards_show_what_is_available_for_each_option(): void
    {
        $staff = User::factory()->superAdmin()->create();
        $location = StockLocation::factory()->create();
        $reward = Reward::factory()->create();
        $small = RewardVariant::factory()->for($reward)->create();
        $large = RewardVariant::factory()->for($reward)->create();
        Reward::factory()->inactive()->create();

        app(StockLedger::class)->receive($staff, [
            'reward_id' => $reward->id, 'variant_id' => $small->id, 'location_id' => $location->id,
            'quantity' => 4, 'source' => 'PURCHASE', 'unit_cost' => 1,
        ]);

        $this->getJson('/api/storefront')
            ->assertOk()
            ->assertJsonCount(1, 'data.rewards')
            ->assertJsonPath('data.rewards.0.stock', 4)
            ->assertJsonPath("data.rewards.0.available.{$small->id}", 4)
            ->assertJsonPath("data.rewards.0.available.{$large->id}", 0)
            ->assertJsonCount(2, 'data.rewards.0.variants');
    }

    public function test_a_reward_with_no_options_is_available_under_a_blank_key(): void
    {
        $staff = User::factory()->superAdmin()->create();
        $location = StockLocation::factory()->create();
        $reward = RewardStockItem::factory()->create(['allow_backorder' => true])->reward;
        app(StockLedger::class)->receive($staff, [
            'reward_id' => $reward->id, 'location_id' => $location->id, 'quantity' => 2, 'source' => 'PURCHASE', 'unit_cost' => 1,
        ]);

        $response = $this->getJson('/api/storefront')->assertOk();

        $this->assertSame(['' => 2], $response->json('data.rewards.0.available'));
        $this->assertTrue($response->json('data.rewards.0.stock_item.allow_backorder'));
        $this->assertArrayNotHasKey('unit_cost', $response->json('data.rewards.0.stock_item'));
    }

    public function test_only_running_campaigns_are_listed_with_the_exchange_rate(): void
    {
        Campaign::factory()->create(['name' => 'Khmer New Year']);
        Campaign::factory()->inactive()->create();
        Setting::put(Setting::EXCHANGE_RATE, 4100);

        $this->getJson('/api/storefront')
            ->assertOk()
            ->assertJsonCount(1, 'data.campaigns')
            ->assertJsonPath('data.campaigns.0.name', 'Khmer New Year')
            ->assertJsonPath('data.exchange_rate', 4100);
    }
}
