<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = Str::title(fake()->unique()->words(2, true));

        return [
            'code' => Str::snake($label),
            'label' => $label,
            'stock_authority' => 'clerk',
        ];
    }

    /**
     * Indicate that the role is Super Admin, which has every page.
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => Role::SUPER_ADMIN,
            'label' => 'Super Admin',
            'stock_authority' => null,
        ]);
    }

    /**
     * Give the role the given pages once it is created.
     *
     * @param  list<string>  $pages
     */
    public function withPages(array $pages): static
    {
        return $this->afterCreating(fn (Role $role) => $role->syncPages($pages));
    }
}
