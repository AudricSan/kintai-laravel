<?php

namespace Kintai\Bundles\ResignationReport\Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kintai\Bundles\ResignationReport\Models\ResignationReport;

/**
 * @extends Factory<ResignationReport>
 */
class ResignationReportFactory extends Factory
{
    protected $model = ResignationReport::class;

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
            'resignation_date' => fake()->date(),
            'reason' => fake()->sentence(),
            'person_in_charge' => fake()->name(),
        ];
    }
}
