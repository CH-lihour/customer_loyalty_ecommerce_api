<?php

namespace Tests\Feature;

use App\Models\Reward;
use App\Models\RewardStockItem;
use App\Models\RewardVariant;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private StockLocation $warehouse;

    private StockLocation $store;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->superAdmin()->create(['name' => 'Dara Admin']));
        $this->warehouse = StockLocation::factory()->create(['name' => 'Phnom Penh Warehouse']);
        $this->store = StockLocation::factory()->create(['name' => 'Siem Reap Store', 'type' => 'store']);
    }

    /**
     * Record a movement through the API.
     *
     * @param  array<string, mixed>  $fields
     */
    private function move(string $type, Reward $reward, array $fields = []): TestResponse
    {
        return $this->postJson('/api/backend/stock-movements', [
            'type' => $type,
            'reward_id' => $reward->id,
            'location_id' => $this->warehouse->id,
            'quantity' => 1,
            ...($type === 'receive' ? ['source' => 'PURCHASE', 'unit_cost' => 2] : []),
            ...$fields,
        ]);
    }

    public function test_snapshot_lists_rewards_with_default_stock_rules_and_the_ledger(): void
    {
        $reward = Reward::factory()->create(['name' => 'Silk Krama']);
        $this->move('receive', $reward, ['quantity' => 12, 'reference' => 'PO-1'])->assertCreated();

        $this->getJson('/api/backend/stock')
            ->assertOk()
            ->assertJsonPath('data.rewards.0.name', 'Silk Krama')
            ->assertJsonPath('data.rewards.0.stock_item.sku', "RW-{$reward->id}")
            ->assertJsonPath('data.rewards.0.stock_item.tracking', 'none')
            ->assertJsonCount(2, 'data.locations')
            ->assertJsonPath('data.movements.0.type', 'RECEIVE')
            ->assertJsonPath('data.movements.0.on_hand_delta', 12)
            ->assertJsonPath('data.movements.0.reason', 'Purchase receipt')
            ->assertJsonPath('data.movements.0.actor_name', 'Dara Admin');
    }

    public function test_receive_needs_an_active_location_and_a_whole_quantity(): void
    {
        $reward = Reward::factory()->create();
        $closed = StockLocation::factory()->inactive()->create(['name' => 'Old Kiosk']);

        $this->move('receive', $reward, ['location_id' => $closed->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.location_id.0', 'Old Kiosk is inactive.');

        $this->move('receive', $reward, ['quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity');
    }

    public function test_batch_items_keep_the_first_expiry_and_issue_first_expiry_first_out(): void
    {
        $reward = RewardStockItem::factory()->batchTracked()->create()->reward;
        $this->move('receive', $reward, ['quantity' => 5, 'batch' => 'late', 'expires_at' => now()->addDays(90)->toDateString()])->assertCreated();
        $this->move('receive', $reward, ['quantity' => 3, 'batch' => 'EARLY', 'expires_at' => now()->addDays(10)->toDateString()])->assertCreated();

        $this->move('receive', $reward, ['batch' => 'LATE', 'expires_at' => now()->addDays(5)->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('expires_at');

        $this->move('transfer', $reward, ['quantity' => 4, 'to_location_id' => $this->store->id])
            ->assertCreated()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.type', 'TRANSFER_OUT')
            ->assertJsonPath('data.0.batch', 'EARLY')
            ->assertJsonPath('data.0.on_hand_delta', -3)
            ->assertJsonPath('data.1.batch', 'LATE')
            ->assertJsonPath('data.1.on_hand_delta', -1)
            ->assertJsonPath('data.2.type', 'TRANSFER_IN')
            ->assertJsonPath('data.2.location_id', $this->store->id);
    }

    public function test_stock_out_cannot_exceed_what_is_available(): void
    {
        $reward = Reward::factory()->create();
        $this->move('receive', $reward, ['quantity' => 2])->assertCreated();

        $this->move('adjust', $reward, ['quantity' => 3, 'direction' => 'out', 'reason' => 'Shelf check'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quantity.0', 'Only 2 units available here.');

        $this->move('transfer', $reward, ['to_location_id' => $this->warehouse->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.to_location_id.0', 'Choose a different destination.');
    }

    public function test_expiry_write_offs_only_take_expired_batches(): void
    {
        $reward = RewardStockItem::factory()->batchTracked()->create()->reward;
        $this->move('receive', $reward, ['quantity' => 4, 'batch' => 'OLD', 'expires_at' => now()->subDay()->toDateString()])->assertCreated();
        $this->move('receive', $reward, ['quantity' => 6, 'batch' => 'NEW', 'expires_at' => now()->addMonth()->toDateString()])->assertCreated();

        $this->move('writeoff', $reward, ['quantity' => 5, 'kind' => 'EXPIRY', 'reason' => 'Expired'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.quantity.0', 'Not enough expired stock here.');

        $this->move('writeoff', $reward, ['quantity' => 4, 'kind' => 'EXPIRY', 'reason' => 'Expired'])
            ->assertCreated()
            ->assertJsonPath('data.0.type', 'EXPIRY')
            ->assertJsonPath('data.0.batch', 'OLD');
    }

    public function test_serial_items_need_one_unique_serial_per_unit(): void
    {
        $reward = RewardStockItem::factory()->serialTracked()->create()->reward;
        $variant = RewardVariant::factory()->for($reward)->create();

        $this->move('receive', $reward, ['quantity' => 2, 'serials' => ['kr-1']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variant_id');

        $this->move('receive', $reward, ['variant_id' => $variant->id, 'quantity' => 2, 'serials' => ['kr-1', 'KR-1']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.serials.0', 'Enter 2 unique serial numbers (one per unit).');

        $this->move('receive', $reward, ['variant_id' => $variant->id, 'quantity' => 2, 'serials' => ['kr-1', 'kr-2']])
            ->assertCreated()
            ->assertJsonPath('data.0.serials', ['KR-1', 'KR-2']);

        $this->move('receive', $reward, ['variant_id' => $variant->id, 'serials' => ['KR-2']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.serials.0', 'Serial KR-2 is already in stock.');

        $this->move('writeoff', $reward, ['variant_id' => $variant->id, 'kind' => 'LOSS', 'reason' => 'Missing', 'serials' => ['KR-9']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.serials.0', 'Serial KR-9 is not in stock here.');
    }

    public function test_adjustments_and_write_offs_need_stock_manager_authority(): void
    {
        $reward = Reward::factory()->create();
        $this->move('receive', $reward, ['quantity' => 5])->assertCreated();
        Sanctum::actingAs(User::factory()->withPages(['stock', 'stock-movements'])->create());

        $this->move('receive', $reward)->assertCreated();
        $this->move('return', $reward, ['reason' => 'Unopened'])->assertCreated();
        $this->move('adjust', $reward, ['direction' => 'in', 'reason' => 'Found'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This action needs stock manager authority.');
        $this->move('writeoff', $reward, ['kind' => 'DAMAGE', 'reason' => 'Crushed'])->assertForbidden();
    }

    public function test_roles_without_an_inventory_page_cannot_see_stock(): void
    {
        Sanctum::actingAs(User::factory()->withPages(['products'])->create());

        $this->getJson('/api/backend/stock')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your role has no inventory access.');
    }

    public function test_movement_list_is_paginated_and_filtered_with_the_net_change(): void
    {
        $coffee = Reward::factory()->create();
        $voucher = Reward::factory()->create();
        $this->move('receive', $coffee, ['quantity' => 10])->assertCreated();
        $this->move('adjust', $coffee, ['quantity' => 3, 'direction' => 'out', 'reason' => 'Damaged box'])->assertCreated();
        $this->move('receive', $voucher, ['quantity' => 50])->assertCreated();

        $this->getJson("/api/backend/stock-movements?reward_id={$coffee->id}&per_page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'ADJUST')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('net_on_hand', 7);

        $this->getJson('/api/backend/stock-movements?type=RECEIVE&from='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('net_on_hand', 60);
    }
}
