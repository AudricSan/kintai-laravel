<?php

namespace Kintai\Bundles\HiringReport\Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kintai\Bundles\HiringReport\Models\HiringReport;

/**
 * @extends Factory<HiringReport>
 */
class HiringReportFactory extends Factory
{
    protected $model = HiringReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'user_id' => null,
            'employee_number' => (string) fake()->numberBetween(1000, 9999),
            'employee_name' => fake()->name(),
            'gender' => fake()->randomElement(['male', 'female']),
            'tax_classification' => fake()->randomElement(['kou', 'otsu']),
            'birth_date' => fake()->date(),
            'hire_date' => fake()->date(),
            'postal_code' => fake()->numerify('###-####'),
            'address' => fake()->address(),
            'phone' => fake()->numerify('03-####-####'),
            'email' => fake()->safeEmail(),
        ];
    }
}
