<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->superAdmin()->create());
    }

    public function test_index_lists_roles_with_pages_and_user_counts(): void
    {
        $role = Role::factory()->withPages(['dashboard', 'products'])->create(['label' => 'Store Supervisor']);
        User::factory()->for($role)->create();

        $this->getJson('/api/backend/roles')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'super_admin')
            ->assertJsonPath('data.0.pages', Role::PAGES)
            ->assertJsonPath('data.1.label', 'Store Supervisor')
            ->assertJsonPath('data.1.pages', ['dashboard', 'products', 'profile'])
            ->assertJsonPath('data.1.users_count', 1);
    }

    public function test_role_is_created_with_dashboard_access_and_a_code(): void
    {
        $this->postJson('/api/backend/roles', ['label' => '  Store   Supervisor '])
            ->assertCreated()
            ->assertJsonPath('data.code', 'store_supervisor')
            ->assertJsonPath('data.label', 'Store Supervisor')
            ->assertJsonPath('data.stock_authority', 'clerk')
            ->assertJsonPath('data.pages', ['dashboard', 'profile']);
    }

    public function test_role_name_must_be_unique_ignoring_case(): void
    {
        Role::factory()->create(['label' => 'Store Supervisor']);

        $this->postJson('/api/backend/roles', ['label' => 'store supervisor'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['label' => 'A role with that name already exists.']);
    }

    public function test_role_pages_and_stock_authority_can_be_updated(): void
    {
        $role = Role::factory()->withPages(['dashboard'])->create();

        $this->patchJson("/api/backend/roles/{$role->id}", ['pages' => ['products', 'categories'], 'stock_authority' => 'manager'])
            ->assertOk()
            ->assertJsonPath('data.pages', ['products', 'categories', 'profile'])
            ->assertJsonPath('data.stock_authority', 'manager');

        $this->assertDatabaseMissing('role_permissions', ['role_id' => $role->id, 'page' => 'dashboard']);
    }

    public function test_role_cannot_get_reserved_pages_or_only_my_profile(): void
    {
        $role = Role::factory()->create();

        $this->patchJson("/api/backend/roles/{$role->id}", ['pages' => ['users']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pages.0');

        $this->patchJson("/api/backend/roles/{$role->id}", ['pages' => ['profile']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['pages' => 'Select at least one section for this role.']);
    }

    public function test_super_admin_role_cannot_be_changed_or_deleted(): void
    {
        $role = Role::where('code', Role::SUPER_ADMIN)->first();

        $this->patchJson("/api/backend/roles/{$role->id}", ['pages' => ['dashboard']])->assertUnprocessable();
        $this->deleteJson("/api/backend/roles/{$role->id}")->assertUnprocessable();
    }

    public function test_role_with_users_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $used = Role::factory()->create(['label' => 'Packers']);
        User::factory()->for($used)->create();
        $unused = Role::factory()->create();

        $this->deleteJson("/api/backend/roles/{$used->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Move the 1 staff user with the Packers role to another role first.');

        $this->deleteJson("/api/backend/roles/{$unused->id}")->assertOk();
        $this->assertSoftDeleted($unused);
        $this->getJson("/api/backend/roles/{$unused->id}")->assertNotFound();
    }

    public function test_recreating_a_deleted_role_gets_a_new_code(): void
    {
        $this->postJson('/api/backend/roles', ['label' => 'Store Supervisor'])->assertCreated();
        Role::where('code', 'store_supervisor')->first()->delete();

        $this->postJson('/api/backend/roles', ['label' => 'Store Supervisor'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'store_supervisor_2');
    }

    public function test_deleted_role_cannot_be_assigned_to_a_user(): void
    {
        $role = Role::factory()->create();
        $role->delete();

        $this->postJson('/api/backend/users', [
            'name' => 'Kanha',
            'email' => 'kanha@example.com',
            'password' => 'secret-pass',
            'role_id' => $role->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('role_id');
    }

    public function test_only_super_admin_can_manage_roles(): void
    {
        Sanctum::actingAs(User::factory()->withPages(['dashboard', 'products'])->create());

        $this->getJson('/api/backend/roles')->assertForbidden();
        $this->postJson('/api/backend/roles', ['label' => 'Sneaky'])->assertForbidden();
    }
}
