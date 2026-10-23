<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class RoleSeeder extends Seeder
{
    /**
     * Seed the default staff roles and their pages, matching the frontend's defaults.
     */
    public function run(): void
    {
        $stockPages = ['stock-alerts', 'stock', 'stock-movements', 'fulfillment', 'stocktake', 'stock-reports', 'suppliers'];

        $roles = [
            ['code' => Role::SUPER_ADMIN, 'label' => 'Super Admin', 'stock_authority' => null, 'pages' => []],
            ['code' => 'marketing_manager', 'label' => 'Marketing Manager', 'stock_authority' => 'clerk', 'pages' => ['dashboard', 'analytics', 'products', 'categories', 'campaigns', 'rewards']],
            ['code' => 'loyalty_manager', 'label' => 'Loyalty Program Manager', 'stock_authority' => 'clerk', 'pages' => ['dashboard', 'customers', 'points', 'tiers', 'rewards', 'badges', 'referrals', 'logs', 'analytics']],
            ['code' => 'stock_manager', 'label' => 'Stock Manager', 'stock_authority' => 'manager', 'pages' => ['dashboard', 'rewards', ...$stockPages]],
            ['code' => 'stock_clerk', 'label' => 'Stock Clerk', 'stock_authority' => 'clerk', 'pages' => ['stock-alerts', 'stock', 'stock-movements', 'fulfillment', 'stocktake']],
        ];

        foreach ($roles as $role) {
            $saved = Role::withTrashed()->firstOrCreate(['code' => $role['code']], Arr::except($role, 'pages'));

            // Only new roles get the defaults, so re-seeding keeps permissions edited since.
            if (! $saved->isSuperAdmin() && $saved->wasRecentlyCreated) {
                $saved->syncPages($role['pages']);
            }
        }
    }
}
