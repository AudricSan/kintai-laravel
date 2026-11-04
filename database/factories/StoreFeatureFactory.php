<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\StoreFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreFeature>
 */
class StoreFeatureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'feature' => fake()->unique()->slug(1),
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['enabled' => false]);
    }
}
