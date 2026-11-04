<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\RewardStockMovement;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_demo_customer_can_login(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->postJson('/api/login', [
            'email' => 'customer@example.com',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_seeder_can_run_twice(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('tiers', 4);
        $this->assertDatabaseCount('badges', 7);
        $this->assertDatabaseCount('rewards', 8);
        $this->assertDatabaseCount('campaigns', 3);
        $this->assertDatabaseCount('loyalty_transactions', 12);
        $this->assertDatabaseCount('stock_locations', 3);
        // Stock and the demo orders and referrals are only seeded once.
        $this->assertDatabaseCount('inventories', 12);
        $this->assertDatabaseCount('orders', 6);
        $this->assertDatabaseCount('referrals', 3);
        // Opening balances are only written once.
        $this->assertSame(17, RewardStockMovement::count());
        $this->assertSame(1, Customer::where('email', 'customer@example.com')->count());
    }
}
