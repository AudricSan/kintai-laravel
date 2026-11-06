<?php

namespace Kintai\Bundles\GoogleCalendar\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Kintai\Bundles\GoogleCalendar\Models\CalendarConnection;

/**
 * @extends Factory<CalendarConnection>
 */
class CalendarConnectionFactory extends Factory
{
    protected $model = CalendarConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_email' => fake()->unique()->safeEmail(),
            'refresh_token' => 'refresh-'.fake()->unique()->lexify('??????????'),
            'calendar_id' => null,
            'calendar_name' => null,
            'status' => CalendarConnection::STATUS_ACTIVE,
            'name_mode' => CalendarConnection::NAME_INSTANCE,
        ];
    }

    /**
     * Une liaison dont l'agenda existe déjà chez Google.
     */
    public function withCalendar(string $id = 'cal-1', string $name = 'Kintai'): static
    {
        return $this->state(['calendar_id' => $id, 'calendar_name' => $name]);
    }

    public function revoked(): static
    {
        return $this->state(['status' => CalendarConnection::STATUS_REVOKED]);
    }
}
