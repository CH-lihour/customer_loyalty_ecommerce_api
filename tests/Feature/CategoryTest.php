<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
    }

    public function test_index_lists_categories_with_product_counts(): void
    {
        $coffee = Category::factory()->create(['name' => 'Coffee & Tea']);
        Category::factory()->inactive()->create(['name' => 'Snacks']);
        Product::factory()->count(2)->for($coffee)->create();

        $this->getJson('/api/backend/categories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Coffee & Tea')
            ->assertJsonPath('data.0.products_count', 2);

        $this->getJson('/api/backend/categories?search=snack')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_active', false);
    }

    public function test_index_is_paginated_30_per_page_by_default(): void
    {
        Category::factory()->count(35)->create();

        $this->getJson('/api/backend/categories')
            ->assertOk()
            ->assertJsonCount(30, 'data')
            ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 30, 'total' => 35, 'last_page' => 2]);

        $this->getJson('/api/backend/categories?per_page=10&page=4')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.last_page', 4);
    }

    public function test_page_size_is_limited_to_100(): void
    {
        $this->getJson('/api/backend/categories?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_category_can_be_created(): void
    {
        $this->postJson('/api/backend/categories', [
            'name' => '  Beauty ',
            'name_kh' => 'សម្ផស្ស',
            'description' => 'Skincare and natural cosmetics.',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Beauty')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.products_count', 0);

        $this->assertDatabaseHas('categories', ['name' => 'Beauty', 'name_kh' => 'សម្ផស្ស']);
    }

    public function test_category_name_is_required_short_and_unique_ignoring_case(): void
    {
        Category::factory()->create(['name' => 'Snacks']);

        $this->postJson('/api/backend/categories', ['name' => 'SNACKS'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'A category named SNACKS already exists.']);

        $this->postJson('/api/backend/categories', ['name' => str_repeat('a', 41)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/backend/categories', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_category_can_be_updated_keeping_its_own_name(): void
    {
        $category = Category::factory()->create(['name' => 'Snacks']);

        $this->putJson("/api/backend/categories/{$category->id}", [
            'name' => 'Snacks',
            'description' => 'Dried fruit, chips, and treats.',
        ])->assertOk()->assertJsonPath('data.description', 'Dried fruit, chips, and treats.');
    }

    public function test_category_status_can_be_toggled_with_patch(): void
    {
        $category = Category::factory()->create(['name' => 'Snacks']);

        $this->patchJson("/api/backend/categories/{$category->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.name', 'Snacks');
    }

    public function test_category_cannot_be_its_own_parent(): void
    {
        $category = Category::factory()->create();

        $this->patchJson("/api/backend/categories/{$category->id}", ['parent_id' => $category->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_category_can_be_created_under_a_parent_and_moved_back_to_the_top(): void
    {
        $drinks = Category::factory()->create(['name' => 'Drinks']);

        $id = $this->postJson('/api/backend/categories', ['name' => 'Coffee', 'parent_id' => $drinks->id])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $drinks->id)
            ->assertJsonPath('data.parent.name', 'Drinks')
            ->json('data.id');

        $this->getJson('/api/backend/categories?search=coffee')
            ->assertJsonPath('data.0.parent.name', 'Drinks');

        $this->patchJson("/api/backend/categories/{$id}", ['parent_id' => null])
            ->assertOk()
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.parent', null);
    }

    public function test_category_cannot_move_under_its_own_sub_category(): void
    {
        $drinks = Category::factory()->create();
        $coffee = Category::factory()->create(['parent_id' => $drinks->id]);
        $beans = Category::factory()->create(['parent_id' => $coffee->id]);

        $this->patchJson("/api/backend/categories/{$drinks->id}", ['parent_id' => $beans->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id' => 'A category cannot be moved under one of its own sub-categories.']);

        $this->patchJson("/api/backend/categories/{$beans->id}", ['parent_id' => $drinks->id])->assertOk();
    }

    public function test_unexpected_failure_returns_a_json_error_and_saves_nothing(): void
    {
        config(['app.debug' => false]);
        Category::creating(fn () => throw new RuntimeException('Database went away'));

        $this->postJson('/api/backend/categories', ['name' => 'Snacks'])
            ->assertInternalServerError()
            ->assertExactJson(['success' => false, 'message' => 'Could not create the category.']);

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_category_with_sub_categories_cannot_be_deleted(): void
    {
        $drinks = Category::factory()->create(['name' => 'Drinks']);
        Category::factory()->create(['parent_id' => $drinks->id]);

        $this->deleteJson("/api/backend/categories/{$drinks->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Move the 1 sub-category in Drinks to another parent first.');

        $this->assertNotSoftDeleted($drinks);
    }

    public function test_empty_category_is_soft_deleted_and_hidden_from_the_api(): void
    {
        $category = Category::factory()->create();

        $this->deleteJson("/api/backend/categories/{$category->id}")->assertOk();

        $this->assertSoftDeleted($category);
        $this->getJson('/api/backend/categories')->assertJsonCount(0, 'data');
        $this->getJson("/api/backend/categories/{$category->id}")->assertNotFound();
    }

    public function test_category_whose_products_are_all_deleted_can_be_deleted(): void
    {
        $category = Category::factory()->create();
        Product::factory()->for($category)->create()->delete();

        $this->deleteJson("/api/backend/categories/{$category->id}")->assertOk();

        $this->assertSoftDeleted($category);
    }

    public function test_deleted_category_name_can_be_reused(): void
    {
        Category::factory()->create(['name' => 'Snacks'])->delete();

        $this->postJson('/api/backend/categories', ['name' => 'Snacks'])->assertCreated();
    }

    public function test_category_with_products_cannot_be_deleted(): void
    {
        $category = Category::factory()->create(['name' => 'Snacks']);
        Product::factory()->count(2)->for($category)->create();

        $this->deleteJson("/api/backend/categories/{$category->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Move the 2 products in Snacks to another category first.');

        $this->assertModelExists($category);
    }

    public function test_staff_without_the_categories_page_are_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->withPages(['dashboard'])->create());

        $this->getJson('/api/backend/categories')->assertForbidden();
    }

    public function test_customer_token_cannot_manage_categories(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(Customer::factory()->create(), guard: 'customer');

        $this->getJson('/api/backend/categories')->assertUnauthorized();
    }
}
