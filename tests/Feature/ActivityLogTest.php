<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->superAdmin()->create(['name' => 'Dara Admin']);
        Sanctum::actingAs($this->staff);
    }

    public function test_staff_changes_are_logged_with_who_made_them(): void
    {
        $category = Category::factory()->create();

        $this->postJson('/api/backend/products', ['category_id' => $category->id, 'name' => 'Silk Krama', 'price' => 12])->assertCreated();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->staff->id,
            'event' => 'PRODUCT_CREATED',
            'description' => 'Product created: Silk Krama',
        ]);
    }

    public function test_reads_and_rejected_changes_are_not_logged(): void
    {
        $this->getJson('/api/backend/products')->assertOk();
        $this->postJson('/api/backend/products', ['name' => ''])->assertUnprocessable();

        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_the_log_can_be_read_and_filtered_newest_first(): void
    {
        $customer = Customer::factory()->create(['name' => 'Sokha']);
        ActivityLog::record('ORDER_CREATED', 'Created order ORD-000001', $customer);
        ActivityLog::record('EXCHANGE_RATE_UPDATED', 'Changed the exchange rate', staff: $this->staff);

        $this->getJson('/api/backend/activity-logs')
            ->assertOk()
            ->assertJsonPath('data.0.event', 'EXCHANGE_RATE_UPDATED')
            ->assertJsonPath('data.0.actor_name', 'Dara Admin')
            ->assertJsonPath('data.1.actor_name', 'Sokha');

        $this->getJson('/api/backend/activity-logs?event=ORDER_CREATED')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/backend/activity-logs?search=exchange')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_log_needs_a_page_that_uses_it(): void
    {
        Sanctum::actingAs(User::factory()->withPages(['products'])->create());

        $this->getJson('/api/backend/activity-logs')->assertForbidden();
    }
}
