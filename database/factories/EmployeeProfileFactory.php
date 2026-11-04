<?php

namespace Database\Factories;

use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeProfile>
 */
class EmployeeProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'gender' => fake()->randomElement(['male', 'female', 'other']),
            'birth_date' => fake()->date(),
            'address' => fake()->streetAddress(),
            'postal_code' => fake()->postcode(),
            'phone' => fake()->phoneNumber(),
            'mobile_phone' => fake()->phoneNumber(),
        ];
    }
}
