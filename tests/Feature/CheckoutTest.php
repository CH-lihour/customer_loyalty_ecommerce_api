<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\User;
use App\Services\ProductStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create(['name' => 'Sokha', 'province' => 'Siem Reap']);
        Sanctum::actingAs($this->customer, guard: 'customer');
    }

    /**
     * A product with stock to sell.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function productWithStock(int $stock, array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes);
        app(ProductStock::class)->set($product, $stock);

        return $product;
    }

    /**
     * Check out through the API.
     *
     * @param  list<array{product_id: int, quantity: int}>  $items
     * @param  array<string, mixed>  $fields
     */
    private function checkout(array $items, array $fields = []): TestResponse
    {
        return $this->postJson('/api/checkout', ['items' => $items, 'address' => '12 Street 240', 'phone' => '012 345 678', ...$fields]);
    }

    public function test_an_order_is_priced_from_the_catalog_and_takes_stock(): void
    {
        $product = $this->productWithStock(5, ['price' => 12.50, 'normal_points' => 12, 'bonus_multiplier' => 2]);

        $this->checkout([['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 0.01]])
            ->assertCreated()
            ->assertJsonPath('data.status', Order::PENDING)
            ->assertJsonPath('data.total', 25)
            ->assertJsonPath('data.customer_name', 'Sokha')
            ->assertJsonPath('data.delivery_address', '12 Street 240')
            ->assertJsonPath('data.payment.method', Payment::CASH_ON_DELIVERY)
            ->assertJsonPath('data.payment.status', Payment::PENDING)
            ->assertJsonPath('data.items.0.unit_price', 12.5)
            ->assertJsonPath('data.items.0.base_points', 12)
            ->assertJsonPath('data.items.0.bonus_multiplier', 2);

        $this->assertSame(3, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => ProductStockMovement::SALE,
            'quantity' => -2,
            'reference_id' => Order::sole()->id,
        ]);
        $this->assertDatabaseHas('addresses', ['customer_id' => $this->customer->id, 'street' => '12 Street 240', 'city' => 'Siem Reap']);
    }

    public function test_the_same_product_on_two_lines_is_one_order_line(): void
    {
        $product = $this->productWithStock(5);

        $this->checkout([['product_id' => $product->id, 'quantity' => 2], ['product_id' => $product->id, 'quantity' => 1]])
            ->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 3);

        $this->assertSame(2, $product->fresh()->stock);
    }

    public function test_ordering_more_than_is_in_stock_changes_nothing(): void
    {
        $plenty = $this->productWithStock(5);
        $scarce = $this->productWithStock(1, ['name' => 'Silk Krama']);

        $this->checkout([['product_id' => $plenty->id, 'quantity' => 1], ['product_id' => $scarce->id, 'quantity' => 2]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.items.0', 'Only 1 of Silk Krama left in stock.');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $plenty->fresh()->stock);
        $this->assertSame(1, $scarce->fresh()->stock);
    }

    public function test_products_that_are_gone_cannot_be_ordered(): void
    {
        $archived = $this->productWithStock(5);
        $archived->delete();

        $this->checkout([['product_id' => $archived->id, 'quantity' => 1]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.items.0', 'An item in your cart is no longer available.');

        $this->checkout([['product_id' => 999999, 'quantity' => 1]])->assertUnprocessable();
    }

    public function test_checkout_details_are_validated(): void
    {
        $product = $this->productWithStock(5);

        $this->checkout([])->assertUnprocessable()->assertJsonPath('errors.items.0', 'Your cart is empty.');
        $this->checkout([['product_id' => $product->id, 'quantity' => 0]])->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity');
        $this->checkout([['product_id' => $product->id, 'quantity' => 1]], ['address' => ' '])->assertUnprocessable()->assertJsonValidationErrors('address');
        $this->checkout([['product_id' => $product->id, 'quantity' => 1]], ['phone' => 'call me'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.phone.0', 'Enter a valid phone number.');
    }

    public function test_checkout_is_for_customers_only(): void
    {
        $product = $this->productWithStock(5);
        $this->app['auth']->forgetGuards();

        $this->checkout([['product_id' => $product->id, 'quantity' => 1]])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->checkout([['product_id' => $product->id, 'quantity' => 1]])->assertUnauthorized();
    }

    public function test_stock_set_on_a_product_is_recorded_as_an_adjustment(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $product = $this->productWithStock(5);

        $this->patchJson("/api/backend/products/{$product->id}", ['stock' => 8])
            ->assertOk()
            ->assertJsonPath('data.stock', 8);

        $this->assertSame(1, Inventory::query()->where('product_id', $product->id)->count());
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'type' => ProductStockMovement::ADJUST, 'quantity' => 3]);
    }
}
