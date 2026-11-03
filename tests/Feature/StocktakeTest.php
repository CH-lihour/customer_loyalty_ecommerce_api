<?php

namespace Tests\Feature;

use App\Models\Reward;
use App\Models\RewardStockItem;
use App\Models\StockLocation;
use App\Models\Stocktake;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StocktakeTest extends TestCase
{
    use RefreshDatabase;

    private StockLocation $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->superAdmin()->create(['name' => 'Dara Admin']));
        $this->warehouse = StockLocation::factory()->create(['name' => 'Phnom Penh Warehouse']);
    }

    /**
     * Receive stock of a reward at the warehouse.
     *
     * @param  array<string, mixed>  $fields
     */
    private function receive(Reward $reward, int $quantity, array $fields = []): void
    {
        $this->postJson('/api/backend/stock-movements', [
            'type' => 'receive',
            'reward_id' => $reward->id,
            'location_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'source' => 'PURCHASE',
            'unit_cost' => 1,
            ...$fields,
        ])->assertCreated();
    }

    public function test_stocktake_snapshots_expected_quantities_per_batch(): void
    {
        $coffee = RewardStockItem::factory()->batchTracked()->create()->reward;
        $coffee->update(['category' => 'Free Product']);
        Reward::factory()->create(['category' => 'Discount']);
        $this->receive($coffee, 4, ['batch' => 'A1']);
        $this->receive($coffee, 6, ['batch' => 'B2']);

        $this->postJson('/api/backend/stocktakes', ['location_id' => $this->warehouse->id, 'category' => 'Free Product', 'note' => 'Monthly'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.created_by', 'Dara Admin')
            ->assertJsonCount(2, 'data.lines')
            ->assertJsonPath('data.lines.0.batch', 'A1')
            ->assertJsonPath('data.lines.0.expected', 4)
            ->assertJsonPath('data.lines.1.expected', 6);

        $this->postJson('/api/backend/stocktakes', ['location_id' => $this->warehouse->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Phnom Penh Warehouse already has an open stocktake.');
    }

    public function test_posting_writes_count_variances_against_the_current_level(): void
    {
        $reward = Reward::factory()->create();
        $this->receive($reward, 10);
        $stocktake = Stocktake::find($this->postJson('/api/backend/stocktakes', ['location_id' => $this->warehouse->id])->json('data.id'));
        $line = $stocktake->lines()->first();

        $this->postJson("/api/backend/stocktakes/{$stocktake->id}/post")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Count every line before posting.');

        $this->patchJson("/api/backend/stocktakes/{$stocktake->id}/lines/{$line->id}", ['counted' => 7])
            ->assertOk()
            ->assertJsonPath('data.counted', 7);
        // Stock received after the count started is part of the system quantity at posting.
        $this->receive($reward, 2);

        $this->postJson("/api/backend/stocktakes/{$stocktake->id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.posted_by', 'Dara Admin')
            ->assertJsonPath('data.lines.0.system_at_post', 12);

        $this->assertDatabaseHas('reward_stock_movements', [
            'type' => 'COUNT',
            'reward_id' => $reward->id,
            'on_hand_delta' => -5,
            'reference' => $stocktake->reference(),
        ]);

        $this->patchJson("/api/backend/stocktakes/{$stocktake->id}/lines/{$line->id}", ['counted' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This stocktake is closed.');
    }

    public function test_serial_lines_are_counted_as_zero_or_one(): void
    {
        $reward = RewardStockItem::factory()->serialTracked()->create()->reward;
        $this->receive($reward, 1, ['serials' => ['SN-1']]);
        $stocktake = Stocktake::find($this->postJson('/api/backend/stocktakes', ['location_id' => $this->warehouse->id])->json('data.id'));

        $this->patchJson("/api/backend/stocktakes/{$stocktake->id}/lines/{$stocktake->lines()->first()->id}", ['counted' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('errors.counted.0', 'Serial lines are counted as 0 or 1.');
    }

    public function test_lines_are_scoped_to_their_stocktake(): void
    {
        $reward = Reward::factory()->create();
        $other = StockLocation::factory()->create();
        $this->receive($reward, 1);
        $first = $this->postJson('/api/backend/stocktakes', ['location_id' => $this->warehouse->id])->json('data');
        $second = $this->postJson('/api/backend/stocktakes', ['location_id' => $other->id])->json('data');

        $this->patchJson("/api/backend/stocktakes/{$second['id']}/lines/{$first['lines'][0]['id']}", ['counted' => 1])
            ->assertNotFound();
    }

    public function test_clerks_can_count_but_only_managers_post_or_cancel(): void
    {
        Reward::factory()->create();
        Sanctum::actingAs(User::factory()->withPages(['stocktake'])->create());
        $id = $this->postJson('/api/backend/stocktakes', ['location_id' => $this->warehouse->id])->assertCreated()->json('data.id');

        $this->postJson("/api/backend/stocktakes/{$id}/post")->assertForbidden();
        $this->postJson("/api/backend/stocktakes/{$id}/cancel")->assertForbidden();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->postJson("/api/backend/stocktakes/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');
    }
}
