<?php

namespace Tests\Feature;

use App\Models\Customer;
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
        $this->assertSame(1, Customer::where('email', 'customer@example.com')->count());
    }
}
