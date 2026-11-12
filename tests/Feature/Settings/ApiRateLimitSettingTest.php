<?php

namespace Tests\Feature\Settings;

use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRateLimitSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_limit_is_read_from_the_settings(): void
    {
        $this->app->make(AppSettings::class)->set('api.rate_limit', 2);

        $this->get('/api/v1/ping')->assertOk();
        $this->get('/api/v1/ping')->assertOk();
        $this->get('/api/v1/ping')->assertStatus(429);
    }

    public function test_zero_means_no_limit(): void
    {
        config(['kintai.api.rate_limit' => 2]);
        $this->app->make(AppSettings::class)->set('api.rate_limit', 0);

        for ($i = 0; $i < 8; $i++) {
            $this->get('/api/v1/ping')->assertOk();
        }
    }
}
