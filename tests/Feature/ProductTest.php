<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
    }

    public function test_index_lists_products_with_category_and_filters(): void
    {
        $coffee = Category::factory()->create(['name' => 'Coffee & Tea']);
        Product::factory()->for($coffee)->featured()->create(['name' => 'Kampot Pepper Coffee']);
        Product::factory()->create(['name' => 'Silk Krama Scarf']);

        $this->getJson('/api/backend/products')->assertOk()->assertJsonCount(2, 'data');

        $this->getJson("/api/backend/products?category_id={$coffee->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Kampot Pepper Coffee')
            ->assertJsonPath('data.0.category.name', 'Coffee & Tea')
            ->assertJsonPath('data.0.is_featured', true);

        $this->getJson('/api/backend/products?search=krama')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Silk Krama Scarf');
    }

    public function test_index_page_size_can_be_changed(): void
    {
        Product::factory()->count(3)->create();

        $this->getJson('/api/backend/products?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_product_can_be_created_with_generated_sku_and_defaults(): void
    {
        $category = Category::factory()->create();

        $response = $this->postJson('/api/backend/products', [
            'category_id' => $category->id,
            'name' => 'Kampot Pepper Coffee',
            'name_kh' => 'កាហ្វេម្រេចកំពត',
            'sku' => '',
            'price' => 12,
            'normal_points' => 12,
            'bonus_multiplier' => 2,
            'is_featured' => true,
        ])->assertCreated()
            ->assertJsonPath('data.price', '12.00')
            ->assertJsonPath('data.bonus_multiplier', '2.00')
            ->assertJsonPath('data.cost_price', '0.00')
            ->assertJsonPath('data.category.id', $category->id);

        $this->assertStringStartsWith('PRD-', $response->json('data.sku'));
    }

    public function test_product_values_are_validated(): void
    {
        $this->postJson('/api/backend/products', [
            'category_id' => 999,
            'price' => 0,
            'normal_points' => -1,
            'bonus_multiplier' => 0.5,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'name', 'price', 'normal_points', 'bonus_multiplier']);
    }

    public function test_product_is_created_with_uploaded_images_in_order(): void
    {
        Storage::fake('public');

        $response = $this->post('/api/backend/products', [
            'category_id' => Category::factory()->create()->id,
            'name' => 'Palm Sugar',
            'price' => '4.5',
            'is_featured' => '1',
            'images' => [UploadedFile::fake()->image('front.jpg'), UploadedFile::fake()->image('back.png')],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonCount(2, 'data.images')
            ->assertJsonPath('data.is_featured', true);

        $product = Product::findOrFail($response->json('data.id'));
        [$front, $back] = $product->images->all();
        Storage::disk('public')->assertExists([$front->path, $back->path]);
        $this->assertSame([0, 1], [$front->sort_order, $back->sort_order]);
        $this->assertSame(url('storage/'.$front->path), $response->json('data.images.0.url'));
        $this->assertArrayNotHasKey('path', $response->json('data.images.0'));
    }

    public function test_update_removes_and_adds_images_with_spoofed_patch(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $product->addImages([UploadedFile::fake()->image('old.jpg'), UploadedFile::fake()->image('keep.jpg')]);
        [$old, $keep] = $product->images->all();

        $this->post("/api/backend/products/{$product->id}", [
            '_method' => 'PATCH',
            'remove_image_ids' => [$old->id],
            'images' => [UploadedFile::fake()->image('new.jpg')],
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonCount(2, 'data.images')
            ->assertJsonPath('data.images.0.id', $keep->id);

        $this->assertModelMissing($old);
        Storage::disk('public')->assertMissing($old->path);
        $this->assertSame(2, $product->images()->reorder()->latest('id')->first()->sort_order);
    }

    public function test_external_image_urls_are_returned_as_is(): void
    {
        $product = Product::factory()->create();
        $product->images()->create(['path' => 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd', 'sort_order' => 0]);

        $this->getJson("/api/backend/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.images.0.url', 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd');
    }

    public function test_images_must_be_small_jpg_png_or_webp_files(): void
    {
        Storage::fake('public');

        $this->post('/api/backend/products', [
            'category_id' => Category::factory()->create()->id,
            'name' => 'Bad files',
            'price' => '5',
            'images' => [
                UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->image('huge.jpg')->size(5000),
            ],
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonValidationErrors(['images.0' => 'Each file must be an image.', 'images.1' => 'Each image must be 4 MB or smaller.']);
    }

    public function test_a_product_cannot_have_more_than_eight_images(): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $product->addImages(array_map(fn (int $i) => UploadedFile::fake()->image("{$i}.jpg"), range(1, 7)));

        $this->post("/api/backend/products/{$product->id}", [
            '_method' => 'PATCH',
            'images' => [UploadedFile::fake()->image('8.jpg'), UploadedFile::fake()->image('9.jpg')],
        ], ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonValidationErrors(['images' => 'A product can have at most 8 images; this would make 9.']);

        $this->assertSame(7, $product->images()->count());
    }

    public function test_cannot_remove_another_products_image(): void
    {
        $other = Product::factory()->create();
        $image = $other->images()->create(['path' => 'products/1/a.jpg', 'sort_order' => 0]);
        $product = Product::factory()->create();

        $this->patchJson("/api/backend/products/{$product->id}", ['remove_image_ids' => [$image->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remove_image_ids.0');

        $this->assertModelExists($image);
    }

    public function test_sku_must_be_unique(): void
    {
        Product::factory()->create(['sku' => 'PRD-0001']);

        $this->postJson('/api/backend/products', [
            'category_id' => Category::factory()->create()->id,
            'name' => 'Duplicate',
            'sku' => 'PRD-0001',
            'price' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_inactive_category_cannot_be_assigned_to_a_new_product(): void
    {
        $category = Category::factory()->inactive()->create(['name' => 'Snacks']);

        $this->postJson('/api/backend/products', [
            'category_id' => $category->id,
            'name' => 'Dried Mango Strips',
            'price' => 6,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id' => 'The Snacks category is inactive and cannot be assigned to products.']);
    }

    public function test_product_can_be_updated_while_staying_in_its_inactive_category(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create(['sku' => 'PRD-0001']);
        $category->update(['is_active' => false]);

        $this->putJson("/api/backend/products/{$product->id}", [
            'category_id' => $category->id,
            'name' => 'Renamed',
            'sku' => 'PRD-0001',
            'price' => 20,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.price', '20.00');
    }

    public function test_product_can_be_featured_with_patch(): void
    {
        $product = Product::factory()->create();

        $this->patchJson("/api/backend/products/{$product->id}", ['is_featured' => true])
            ->assertOk()
            ->assertJsonPath('data.is_featured', true);
    }

    public function test_product_is_soft_deleted_and_hidden_from_the_api(): void
    {
        $product = Product::factory()->create();

        $this->deleteJson("/api/backend/products/{$product->id}")->assertOk();

        $this->assertSoftDeleted($product);
        $this->getJson('/api/backend/products')->assertJsonCount(0, 'data');
        $this->getJson("/api/backend/products/{$product->id}")->assertNotFound();
    }

    public function test_product_with_stock_history_can_be_deleted_and_keeps_its_history(): void
    {
        $product = Product::factory()->create();
        $warehouseId = DB::table('warehouses')->insertGetId(['name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_movements')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
            'type' => 'IN',
            'quantity' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/backend/products/{$product->id}")->assertOk();

        $this->assertSoftDeleted($product);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id]);
    }

    public function test_deleted_product_sku_cannot_be_reused(): void
    {
        Product::factory()->create(['sku' => 'PRD-0001'])->delete();

        $this->postJson('/api/backend/products', [
            'category_id' => Category::factory()->create()->id,
            'name' => 'Reused',
            'sku' => 'PRD-0001',
            'price' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_deleted_category_cannot_be_assigned(): void
    {
        $category = Category::factory()->create();
        $category->delete();

        $this->postJson('/api/backend/products', [
            'category_id' => $category->id,
            'name' => 'Orphan',
            'price' => 5,
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }
}
