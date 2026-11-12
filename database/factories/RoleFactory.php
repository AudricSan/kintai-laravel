<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'slug' => fake()->unique()->slug(2),
            'description' => null,
            'color' => '#6B7280',
            'is_system' => false,
            'is_manager' => false,
        ];
    }

    public function manager(): static
    {
        return $this->state(fn (array $attributes) => ['is_manager' => true]);
    }
}
