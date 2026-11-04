<?php

namespace Database\Factories;

use App\Models\DashboardLayout;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardLayout>
 */
class DashboardLayoutFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role_id' => null,
            'dashboard' => DashboardLayout::MANAGER,
            'name' => fake()->words(2, true),
            'layout' => [],
            'is_active' => false,
            'position' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => true]);
    }

    public function employee(): static
    {
        return $this->state(fn (array $attributes) => ['dashboard' => DashboardLayout::EMPLOYEE]);
    }

    /**
     * @param  list<string>  $widgets
     */
    public function widgets(array $widgets): static
    {
        return $this->state(fn (array $attributes) => [
            'layout' => array_map(fn (string $widget): array => ['widget' => $widget], $widgets),
        ]);
    }

    /**
     * Disposition imposée à un rôle (aucun utilisateur).
     */
    public function forRole(int $roleId): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => null, 'role_id' => $roleId, 'is_active' => true]);
    }
}
