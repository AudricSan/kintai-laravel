<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserNavPref;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserNavPref>
 */
class UserNavPrefFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'hidden_items' => [],
            'bottom_nav_items' => null,
        ];
    }
}
