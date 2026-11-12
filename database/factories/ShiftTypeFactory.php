<?php

namespace Database\Factories;

use App\Models\ShiftType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftType>
 */
class ShiftTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'starts_at' => '09:00',
            'ends_at' => '17:00',
            'hourly_rate' => 12.50,
            'color' => '#3B82F6',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
