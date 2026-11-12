<?php

namespace Database\Factories;

use App\Models\ActivityEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityEntry>
 */
class ActivityEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action' => 'store.updated',
            'properties' => [],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'created_at' => now(),
        ];
    }
}
