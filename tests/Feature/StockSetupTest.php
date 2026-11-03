<?php

namespace Tests\Feature;

use App\Models\Reward;
use App\Models\RewardStockItem;
use App\Models\RewardVariant;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
    }

    /**
     * A complete stock rules payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rules(array $overrides = []): array
    {
        return [
            'sku' => ' gf-krama ',
            'unit_cost' => 18,
            'supplier_id' => null,
            'tracking' => 'none',
            'low_stock_threshold' => 2,
            'reorder_point' => 4,
            'reorder_qty' => 10,
            'out_of_stock' => 'disable',
            'allow_backorder' => true,
            'variants' => [],
            ...$overrides,
        ];
    }

    /**
     * Receive one unit of a reward.
     *
     * @param  array<string, mixed>  $fields
     */
    private function receiveOne(Reward $reward, array $fields = []): void
    {
        $this->postJson('/api/backend/stock-movements', [
            'type' => 'receive',
            'reward_id' => $reward->id,
            'location_id' => StockLocation::first()?->id ?? StockLocation::factory()->create()->id,
            'quantity' => 1,
            'source' => 'PURCHASE',
            'unit_cost' => 1,
            ...$fields,
        ])->assertCreated();
    }

    public function test_stock_rules_are_saved_with_generated_variant_skus(): void
    {
        $reward = Reward::factory()->create();
        $supplier = Supplier::factory()->create();

        $this->putJson("/api/backend/stock-items/{$reward->id}", $this->rules([
            'supplier_id' => $supplier->id,
            'variants' => [['size' => 'Standard', 'color' => 'Red', 'sku' => ''], ['size' => '', 'color' => 'Gold', 'sku' => 'krama-gold']],
        ]))->assertOk()
            ->assertJsonPath('data.stock_item.sku', 'GF-KRAMA')
            ->assertJsonPath('data.stock_item.supplier_id', $supplier->id)
            ->assertJsonPath('data.variants.0.sku', 'GF-KRAMA-1')
            ->assertJsonPath('data.variants.1.sku', 'KRAMA-GOLD');

        $other = Reward::factory()->create();
        $this->putJson("/api/backend/stock-items/{$other->id}", $this->rules())
            ->assertUnprocessable()
            ->assertJsonPath('errors.sku.0', 'Another reward already uses that SKU.');
        $this->putJson("/api/backend/stock-items/{$other->id}", $this->rules(['sku' => 'OTHER', 'variants' => [['size' => '', 'color' => '']]]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.variants.0', 'Each variant needs a size or colour.');
    }

    public function test_tracking_and_variants_holding_stock_cannot_change(): void
    {
        $item = RewardStockItem::factory()->create(['sku' => 'TASTE']);
        $variant = RewardVariant::factory()->for($item->reward)->create(['size' => 'Large', 'color' => '']);
        $this->receiveOne($item->reward, ['variant_id' => $variant->id]);

        $this->putJson("/api/backend/stock-items/{$item->reward_id}", $this->rules(['sku' => 'TASTE']))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Large still has stock. Move it to zero first.');

        $this->putJson("/api/backend/stock-items/{$item->reward_id}", $this->rules([
            'sku' => 'TASTE',
            'tracking' => 'batch',
            'variants' => [['id' => $variant->id, 'size' => 'Large', 'color' => '', 'sku' => $variant->sku]],
        ]))->assertUnprocessable()
            ->assertJsonPath('message', 'Tracking can only change while the item has no stock on hand or reserved.');
    }

    public function test_locations_holding_stock_cannot_be_deactivated(): void
    {
        $warehouse = StockLocation::factory()->create(['name' => 'Central']);
        $spare = StockLocation::factory()->create();
        $this->receiveOne(Reward::factory()->create(), ['location_id' => $warehouse->id]);

        $this->patchJson("/api/backend/stock-locations/{$warehouse->id}", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Transfer or write off Central's stock before deactivating it.");

        $this->patchJson("/api/backend/stock-locations/{$spare->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->postJson('/api/backend/stock-locations', ['name' => ' Kampot Kiosk ', 'type' => 'pickup'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Kampot Kiosk')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_suppliers_can_be_added_and_updated(): void
    {
        $id = $this->postJson('/api/backend/suppliers', ['name' => 'Takeo Silk', 'kind' => 'partner', 'email' => '', 'phone' => '+855 32 210 755'])
            ->assertCreated()
            ->assertJsonPath('data.email', null)
            ->json('data.id');

        $this->patchJson("/api/backend/suppliers/{$id}", ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Enter a valid email address.');

        $this->patchJson("/api/backend/suppliers/{$id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_setup_needs_stock_manager_authority(): void
    {
        $reward = Reward::factory()->create();
        Sanctum::actingAs(User::factory()->withPages(['stock', 'suppliers'])->create());

        $this->putJson("/api/backend/stock-items/{$reward->id}", $this->rules())->assertForbidden();
        $this->postJson('/api/backend/suppliers', ['name' => 'X', 'kind' => 'supplier'])->assertForbidden();
        $this->postJson('/api/backend/stock-locations', ['name' => 'X', 'type' => 'store'])->assertForbidden();
    }
}
