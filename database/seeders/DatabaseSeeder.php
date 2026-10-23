<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call(RoleSeeder::class);

        $user = User::firstOrCreate(['email' => 'test@example.com'], User::factory()->raw([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]));
        // The demo staff user is Super Admin, including one seeded before roles existed.
        $user->role_id ??= Role::where('code', Role::SUPER_ADMIN)->value('id');
        $user->save();

        $this->call([
            TierSeeder::class,
            CustomerSeeder::class,
            CategorySeeder::class,
            ProductSeeder::class,
        ]);
    }
}
