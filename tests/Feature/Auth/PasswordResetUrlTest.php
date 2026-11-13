<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_ignores_the_host_header(): void
    {
        Notification::fake();
        config(['app.url' => 'https://kintai.example']);
        $user = User::factory()->create();

        $this->withHeader('Host', 'evil.test')->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user): bool {
            $url = ResetPassword::$createUrlCallback ? call_user_func(ResetPassword::$createUrlCallback, $user, $n->token) : '';

            return str_starts_with($url, 'https://kintai.example/reset-password/'.$n->token)
                && ! str_contains($url, 'evil.test');
        });
    }

    public function test_unknown_address_gets_the_same_answer_as_a_known_one(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.test']);

        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame(session('status'), __('passwords.sent'));
    }

    public function test_reset_link_requests_are_throttled_per_ip(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => "a{$i}@example.test"])->assertSessionHasNoErrors();
        }

        $this->post('/forgot-password', ['email' => 'b@example.test'])->assertStatus(429);
    }
}
