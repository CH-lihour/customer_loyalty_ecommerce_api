<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->superAdmin()->create(['name' => 'Admin']);
        Sanctum::actingAs($this->admin);
    }

    public function test_index_lists_staff_with_role_and_pages(): void
    {
        User::factory()->withPages(['products'])->create(['name' => 'Zed']);

        $this->getJson('/api/backend/users')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.role.code', 'super_admin')
            ->assertJsonPath('data.1.name', 'Zed')
            ->assertJsonPath('data.1.pages', ['products', 'profile']);
    }

    public function test_index_page_size_can_be_changed(): void
    {
        User::factory()->count(2)->create();

        $this->getJson('/api/backend/users?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_staff_user_can_be_created_and_log_in(): void
    {
        $role = Role::factory()->withPages(['products'])->create();

        $this->postJson('/api/backend/users', [
            'name' => 'Kanha Srey',
            'email' => ' Kanha@Example.com ',
            'password' => 'secret-pass',
            'role_id' => $role->id,
        ])->assertCreated()
            ->assertJsonPath('data.email', 'kanha@example.com')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.role.id', $role->id);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/backend/login', ['email' => 'kanha@example.com', 'password' => 'secret-pass'])->assertOk();
    }

    public function test_staff_user_fields_are_validated(): void
    {
        $this->postJson('/api/backend/users', [
            'email' => $this->admin->email,
            'password' => 'short',
            'role_id' => 999,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'role_id']);
    }

    public function test_update_keeps_the_password_when_left_blank(): void
    {
        $user = User::factory()->withPages(['products'])->create(['password' => 'old-password']);

        $this->patchJson("/api/backend/users/{$user->id}", ['name' => 'Renamed', 'password' => ''])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/backend/login', ['email' => $user->email, 'password' => 'old-password'])->assertOk();
    }

    public function test_disabling_a_user_revokes_tokens_and_blocks_login(): void
    {
        $user = User::factory()->withPages(['products'])->create(['password' => 'secret-pass']);
        $user->createToken('web');

        $this->patchJson("/api/backend/users/{$user->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/backend/login', ['email' => $user->email, 'password' => 'secret-pass'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'This staff account has been disabled.']);
    }

    public function test_admin_cannot_disable_or_demote_themselves(): void
    {
        $role = Role::factory()->create();

        $this->patchJson("/api/backend/users/{$this->admin->id}", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot disable your own account.');

        $this->patchJson("/api/backend/users/{$this->admin->id}", ['role_id' => $role->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot remove your own Super Admin role.');
    }

    public function test_last_active_super_admin_must_stay(): void
    {
        $other = User::factory()->superAdmin()->create();
        $this->admin->update(['is_active' => false]);

        $this->patchJson("/api/backend/users/{$other->id}", ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'At least one active Super Admin is required.');
    }

    public function test_profile_returns_role_and_pages(): void
    {
        Sanctum::actingAs(User::factory()->withPages(['products'])->create());

        $this->getJson('/api/backend/profile')
            ->assertOk()
            ->assertJsonPath('data.pages', ['products', 'profile'])
            ->assertJsonPath('data.role.stock_authority', 'clerk');
    }

    public function test_only_super_admin_can_manage_users(): void
    {
        Sanctum::actingAs(User::factory()->withPages(['products'])->create());

        $this->getJson('/api/backend/users')->assertForbidden();
    }
}
